import { useEffect, useMemo, useRef, useState, type ComponentType, type SVGProps } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Line, LineChart, ResponsiveContainer, YAxis } from 'recharts';
import {
  ArrowPathIcon, CircleStackIcon, CpuChipIcon, HeartIcon, RectangleStackIcon, ServerStackIcon, SignalIcon,
  SignalSlashIcon, Squares2X2Icon,
} from '@heroicons/react/24/outline';
import {
  Alert, Badge, Button, Card, CardHeader, EmptyState, ErrorState, PageHeader, Segmented, Skeleton, StatCard, StatusBadge,
  cx, useToast,
} from '../components/ui';
import { get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, dateTime, ms, num } from '../lib/format';
import { SERIES, scoreText, scoreTone } from './Dashboard/charts';

interface NodeMetric {
  server_id: number; name: string; status: string;
  cpu_pct: number | null; mem_pct: number | null; disk_pct: number | null; gpu_pct: number | null; available: boolean;
  vram_pct?: number | null; gpu_temp_c?: number | null; source?: 'node-app' | 'prometheus';
}
interface HealthSummary { total: number; online: number; degraded: number; offline: number; unknown: number; avg_score: number }
interface ServerRow { id: number; name: string; status: string; health_score: number; last_heartbeat_at: string | null; ip_address?: string | null; api_url?: string; kind?: string; ollama_version?: string | null }
interface CheckResult { server_id: number; name: string; ok: boolean; status: string; health_score: number; latency_ms: number | null; models_total?: number; models_running?: number; error?: string | null }

type Interval = 'off' | '5' | '10' | '15' | '30' | '60';
const INTERVALS: { value: Interval; label: string }[] = [
  { value: 'off', label: 'Off' }, { value: '5', label: '5s' }, { value: '10', label: '10s' }, { value: '15', label: '15s' }, { value: '30', label: '30s' }, { value: '60', label: '1m' },
];
const IKEY = 'aistudio.monitoring.interval';
const HISTORY = 30;
type Sample = { cpu: number | null; mem: number | null; gpu: number | null };

export default function Monitoring() {
  const { can } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const [interval, setIntervalState] = useState<Interval>(() => {
    try { return (localStorage.getItem(IKEY) as Interval) || '5'; } catch { return '5'; }
  });
  const setInterval = (v: Interval) => { setIntervalState(v); try { localStorage.setItem(IKEY, v); } catch { /* ignore */ } };
  const every = interval === 'off' ? false : Number(interval) * 1000;

  const metrics = useQuery({ queryKey: ['metrics'], queryFn: () => get<{ nodes: NodeMetric[]; prometheus: boolean }>('/metrics'), refetchInterval: every });
  const health = useQuery({ queryKey: ['health'], queryFn: () => get<HealthSummary>('/health'), refetchInterval: every });
  const canServers = can('servers.view');
  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<ServerRow[]>('/servers'), enabled: canServers, refetchInterval: every });
  const [lastCheck, setLastCheck] = useState<CheckResult[] | null>(null);

  const refresh = useMutation({
    mutationFn: () => post<CheckResult[]>('/metrics/refresh'),
    onSuccess: (res) => {
      setLastCheck(res);
      const down = res.filter((r) => r.status !== 'online').length;
      toast.success('Nodes re-checked', down ? `${down} of ${res.length} not fully online.` : `All ${res.length} online.`);
      for (const k of ['metrics', 'health', 'servers', 'dashboard']) qc.invalidateQueries({ queryKey: [k] });
    },
    onError: (e) => toast.error(e),
  });

  // Rolling in-browser history per node, so each card shows a short live trend.
  const hist = useRef(new Map<number, Sample[]>());
  const [, bump] = useState(0);
  useEffect(() => {
    if (!metrics.data) return;
    for (const n of metrics.data.nodes) {
      const arr = hist.current.get(n.server_id) ?? [];
      arr.push({ cpu: n.cpu_pct, mem: n.mem_pct, gpu: n.gpu_pct });
      if (arr.length > HISTORY) arr.shift();
      hist.current.set(n.server_id, arr);
    }
    bump((x) => x + 1);
  }, [metrics.dataUpdatedAt, metrics.data]);

  const byId = useMemo(() => new Map((servers.data ?? []).map((s) => [s.id, s])), [servers.data]);
  const checkById = useMemo(() => new Map((lastCheck ?? []).map((c) => [c.server_id, c])), [lastCheck]);
  const h = health.data;
  const nodes = metrics.data?.nodes ?? [];

  return (
    <>
      <PageHeader
        title="Monitoring"
        description="Live system and GPU metrics, reachability and health score for every node."
        meta={
          metrics.dataUpdatedAt ? (
            <span className="inline-flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
              <span className={cx('size-2 rounded-full', metrics.isFetching ? 'animate-pulse bg-amber-500' : every ? 'bg-emerald-500' : 'bg-slate-400')} />
              {every ? 'Live' : 'Paused'} · updated {new Date(metrics.dataUpdatedAt).toLocaleTimeString()}
            </span>
          ) : undefined
        }
        actions={
          <div className="flex flex-wrap items-center gap-3">
            <Segmented label="Auto-refresh" options={INTERVALS} value={interval} onChange={setInterval} />
            <Button variant="secondary" icon={ArrowPathIcon} loading={refresh.isPending} onClick={() => refresh.mutate()}>Re-check nodes</Button>
          </div>
        }
      />

      {metrics.data && !metrics.data.prometheus && (
        <Alert tone="info" className="mb-6" title="Prometheus is not connected">
          CPU, memory, disk and GPU figures appear once Prometheus and node_exporter are running and PROMETHEUS_BASEURL points at them.
          Node reachability and health scores below are live.
        </Alert>
      )}

      {/* Cluster summary */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatCard loading={health.isLoading} name="Nodes" icon={ServerStackIcon} value={h ? num(h.total) : '—'} hint={h?.unknown ? `${h.unknown} not yet checked` : 'Registered'} />
        <StatCard loading={health.isLoading} name="Online" icon={SignalIcon} value={h ? num(h.online) : '—'} hint="Reachable, healthy" />
        <StatCard loading={health.isLoading} name="Degraded" icon={HeartIcon} value={h ? num(h.degraded) : '—'} hint="Reachable but slow"
          change={h?.degraded ? 'check' : undefined} changeTone={h?.degraded ? 'down' : undefined} />
        <StatCard loading={health.isLoading} name="Offline" icon={SignalSlashIcon} value={h ? num(h.offline) : '—'} hint="Not reachable"
          change={h?.offline ? 'down' : undefined} changeTone={h?.offline ? 'down' : undefined} />
        <StatCard loading={health.isLoading} name="Avg health score" icon={Squares2X2Icon}
          value={h ? <span className={scoreText(h.avg_score)}>{h.avg_score}</span> : '—'} hint="Out of 100" />
      </div>
      {health.error && !h && <Card className="mt-4"><ErrorState error={health.error} onRetry={() => health.refetch()} /></Card>}

      {/* Nodes */}
      <div className="mt-6">
        {metrics.isLoading ? (
          <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((i) => <Card key={i} padded><Skeleton className="h-48 w-full" /></Card>)}</div>
        ) : metrics.error && !metrics.data ? (
          <Card><ErrorState error={metrics.error} onRetry={() => metrics.refetch()} /></Card>
        ) : nodes.length === 0 ? (
          <Card>
            <EmptyState icon={ServerStackIcon} title="No servers registered" description="Register an Ollama node or provider to watch it here."
              action={canServers ? <Link to="/servers"><Button>Add a server</Button></Link> : undefined} />
          </Card>
        ) : (
          <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            {nodes.map((n) => (
              <NodeCard key={n.server_id} node={n} server={byId.get(n.server_id)} check={checkById.get(n.server_id)}
                history={hist.current.get(n.server_id) ?? []} linkable={canServers} />
            ))}
          </div>
        )}
      </div>
    </>
  );
}

function NodeCard({ node, server, check, history, linkable }: { node: NodeMetric; server?: ServerRow; check?: CheckResult; history: Sample[]; linkable: boolean }) {
  const score = check?.health_score ?? server?.health_score;
  const status = check?.status ?? node.status;
  const hasTrend = history.filter((s) => s.cpu != null || s.mem != null || s.gpu != null).length > 1;
  return (
    <Card className="flex flex-col">
      <CardHeader
        title={linkable ? <Link to={`/servers/${node.server_id}`} className="hover:text-brand-600 dark:hover:text-blue-400">{node.name}</Link> : node.name}
        description={server ? (server.ip_address || server.api_url || undefined) : undefined}
        actions={<StatusBadge status={status} />}
      />
      <div className="flex-1 space-y-4 px-4 pt-2 pb-4 sm:px-6">
        <div className="grid grid-cols-2 gap-x-6 gap-y-3">
          <Gauge icon={CpuChipIcon} label="CPU" value={node.cpu_pct} />
          <Gauge icon={RectangleStackIcon} label="Memory" value={node.mem_pct} />
          <Gauge icon={CircleStackIcon} label="Disk" value={node.disk_pct} />
          <Gauge icon={Squares2X2Icon} label={node.gpu_temp_c != null ? `GPU · ${Math.round(node.gpu_temp_c)} °C` : 'GPU'} value={node.gpu_pct} />
          {node.vram_pct != null && <Gauge icon={Squares2X2Icon} label="VRAM" value={node.vram_pct} />}
        </div>

        {hasTrend && (
          <div className="text-slate-400 dark:text-slate-500">
            <div className="mb-1 flex items-center gap-3 text-[11px]">
              <span className="inline-flex items-center gap-1"><span className="size-2 rounded-full" style={{ background: SERIES[0] }} />CPU</span>
              <span className="inline-flex items-center gap-1"><span className="size-2 rounded-full" style={{ background: SERIES[1] }} />Memory</span>
              <span className="inline-flex items-center gap-1"><span className="size-2 rounded-full" style={{ background: SERIES[3] }} />GPU</span>
              <span className="ml-auto">this session</span>
            </div>
            <div className="h-12">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={history} margin={{ top: 2, right: 0, left: 0, bottom: 2 }}>
                  <YAxis hide domain={[0, 100]} />
                  <Line dataKey="cpu" stroke={SERIES[0]} strokeWidth={1.5} dot={false} isAnimationActive={false} connectNulls />
                  <Line dataKey="mem" stroke={SERIES[1]} strokeWidth={1.5} dot={false} isAnimationActive={false} connectNulls />
                  <Line dataKey="gpu" stroke={SERIES[3]} strokeWidth={1.5} dot={false} isAnimationActive={false} connectNulls />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </div>
        )}

        {!node.available && <p className="text-center text-xs text-slate-400 dark:text-slate-500">Metrics exporter not reporting for this node.</p>}

        {check?.error && (
          <p className="rounded-md bg-red-50 px-3 py-2 text-xs break-words text-red-700 dark:bg-red-500/10 dark:text-red-300">{check.error}</p>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-200 px-4 py-3 text-xs text-slate-500 sm:px-6 dark:border-white/10 dark:text-slate-400">
        {score !== undefined && (
          <span className="inline-flex items-center gap-2">
            Health
            <span className="h-1.5 w-12 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
              <span className={cx('block h-full rounded-full', scoreTone(score))} style={{ width: `${Math.max(2, score)}%` }} />
            </span>
            <span className={cx('font-semibold tabular-nums', scoreText(score))}>{score}</span>
          </span>
        )}
        {check?.latency_ms != null && <span>Latency <span className="font-medium text-slate-700 tabular-nums dark:text-slate-200">{ms(check.latency_ms)}</span></span>}
        {check?.models_total != null && <Badge tone="gray">{check.models_running ?? 0}/{check.models_total} models running</Badge>}
        {server && (
          <span className="ml-auto" title={server.last_heartbeat_at ? dateTime(server.last_heartbeat_at) : undefined}>
            Heartbeat {server.last_heartbeat_at ? ago(server.last_heartbeat_at) : 'never'}
          </span>
        )}
      </div>
    </Card>
  );
}

function Gauge({ icon: Icon, label, value }: { icon: ComponentType<SVGProps<SVGSVGElement>>; label: string; value: number | null }) {
  const v = value == null ? null : Math.max(0, Math.min(100, value));
  const tone = v == null ? '' : v >= 90 ? 'bg-red-500' : v >= 75 ? 'bg-amber-500' : 'bg-brand-600 dark:bg-blue-500';
  return (
    <div>
      <div className="mb-1 flex items-center justify-between text-xs">
        <span className="inline-flex items-center gap-1 text-slate-500 dark:text-slate-400"><Icon aria-hidden className="size-3.5" />{label}</span>
        <span className="font-semibold text-slate-900 tabular-nums dark:text-white">{v == null ? '—' : `${v.toFixed(0)}%`}</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10" role="meter" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={v ?? undefined}>
        {v != null && <div className={cx('h-full rounded-full transition-[width] duration-500', tone)} style={{ width: `${v}%` }} />}
      </div>
    </div>
  );
}
