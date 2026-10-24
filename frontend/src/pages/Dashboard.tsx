import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Tooltip, XAxis, YAxis } from 'recharts';
import {
  ArrowPathIcon, BoltIcon, ChartBarIcon, ClockIcon, CpuChipIcon, CubeIcon, ExclamationTriangleIcon,
  ServerStackIcon, SignalIcon, UsersIcon,
} from '@heroicons/react/24/outline';
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, ErrorState, PageHeader, Skeleton, StatCard, StatusBadge,
  cx, type Column,
} from '../components/ui';
import { get } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, compact, ms, num } from '../lib/format';
import {
  COMPLETION_COLOR, ChartCard, ChartTooltip, LegendDot, PROMPT_COLOR, SERIES, axisProps, cursorFill, fillDays, fillHours,
  gridProps, hourLabel, scoreTone, shortDay,
} from './Dashboard/charts';

interface DashboardStats {
  total_servers: number; online_servers: number; total_models: number; active_models: number;
  api_requests: number; total_tokens: number; avg_response_ms: number | null; error_rate: number;
  total_users: number; active_users: number; cpu_usage: number | null; gpu_usage: number | null; prometheus: boolean;
}
interface DashboardCharts {
  requests_per_hour: { t: string; requests: number }[];
  tokens_per_day: { t: string; prompt: number; completion: number }[];
  model_usage: { model: string; requests: number }[];
  server_health: { name: string; status: string; score: number }[];
}
interface ApiLogRow {
  id: number; endpoint: string; method: string; model: string | null; status: number; processing_ms: number | null;
  total_tokens: number | null; key_name: string | null; server_name: string | null; created_at: string;
}

const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(1)}%`);
const linkCls = 'text-sm font-medium text-brand-600 hover:text-brand-500 dark:text-blue-400 dark:hover:text-blue-300';

export default function Dashboard() {
  const { user, can } = useAuth();
  const q = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => get<{ stats: DashboardStats; charts: DashboardCharts }>('/dashboard'),
    refetchInterval: 15_000,
    refetchIntervalInBackground: true,
  });
  const canLogs = can('logs.view');
  const logs = useQuery({
    queryKey: ['dashboard', 'recent-activity'],
    queryFn: () => get<{ rows: ApiLogRow[]; total: number }>('/apilogs', { per_page: 8 }),
    enabled: canLogs,
    refetchInterval: 15_000,
  });

  const stats = q.data?.stats;
  const charts = q.data?.charts;
  const loading = q.isLoading;
  const first = user?.name?.split(' ')[0] || 'there';

  const hourly = useMemo(() => fillHours(charts?.requests_per_hour ?? []), [charts]);
  const hourlyTotal = hourly.reduce((a, r) => a + r.requests, 0);
  const daily = useMemo(() => fillDays(charts?.tokens_per_day ?? [], 7, { prompt: 0, completion: 0 }), [charts]);
  const weekTokens = daily.reduce((a, r) => a + r.prompt + r.completion, 0);
  const nodes = useMemo(() => [...(charts?.server_health ?? [])].sort((a, b) => a.score - b.score), [charts]);
  const attention = nodes.filter((n) => n.status !== 'online').length;
  const models = charts?.model_usage ?? [];

  if (q.error && !q.data) {
    return (
      <>
        <PageHeader title={`Welcome, ${first}`} description="Cluster overview" />
        <Card><ErrorState error={q.error} onRetry={() => q.refetch()} /></Card>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={`Welcome, ${first}`}
        description="Cluster overview — servers, models and traffic at a glance. Refreshes every 15 seconds."
        meta={
          q.dataUpdatedAt ? (
            <span className="inline-flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
              <span className={cx('size-2 rounded-full', q.isFetching ? 'animate-pulse bg-amber-500' : 'bg-emerald-500')} />
              Updated {new Date(q.dataUpdatedAt).toLocaleTimeString()}
            </span>
          ) : undefined
        }
        actions={
          <Button variant="secondary" icon={ArrowPathIcon} loading={q.isFetching && !loading}
            onClick={() => { q.refetch(); if (canLogs) logs.refetch(); }}>
            Refresh
          </Button>
        }
      />

      {stats && !stats.prometheus && (
        <Alert tone="info" className="mb-6" title="System metrics are not connected">
          CPU and GPU figures appear once Prometheus is reachable. Request, token and node health figures below are live.
        </Alert>
      )}

      {/* KPI cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard loading={loading} name="Servers online" icon={ServerStackIcon}
          value={stats ? <>{num(stats.online_servers)}<span className="text-base font-medium text-slate-400 dark:text-slate-500"> / {num(stats.total_servers)}</span></> : '—'}
          hint={stats ? `${num(stats.total_servers)} registered · ${num(stats.total_servers - stats.online_servers)} not online` : undefined} />
        <StatCard loading={loading} name="Models" icon={CubeIcon} value={stats ? num(stats.total_models) : '—'}
          hint={stats ? `${num(stats.active_models)} loaded and running` : undefined} />
        <StatCard loading={loading} name="Requests" icon={BoltIcon} value={stats ? compact(stats.api_requests) : '—'}
          hint={stats ? `${num(stats.api_requests)} all time · ${num(hourlyTotal)} in 24 h` : undefined} />
        <StatCard loading={loading} name="Total tokens" icon={ChartBarIcon} value={stats ? compact(stats.total_tokens) : '—'}
          hint={stats ? `${compact(weekTokens)} in the last 7 days` : undefined} />
        <StatCard loading={loading} name="Avg response" icon={ClockIcon}
          value={stats?.avg_response_ms != null ? `${(stats.avg_response_ms / 1000).toFixed(2)} s` : '—'}
          hint="Playground and gateway, all time" />
        <StatCard loading={loading} name="Gateway error rate" icon={ExclamationTriangleIcon} value={stats ? `${stats.error_rate}%` : '—'}
          changeTone={stats && stats.error_rate >= 5 ? 'down' : undefined} change={stats && stats.error_rate >= 5 ? 'high' : undefined}
          hint="Gateway calls answered with status 400 or above" />
        <StatCard loading={loading} name="CPU · GPU" icon={CpuChipIcon}
          value={stats ? <>{pct(stats.cpu_usage)}<span className="text-base font-medium text-slate-400 dark:text-slate-500"> · {pct(stats.gpu_usage)}</span></> : '—'}
          hint={stats ? (stats.prometheus ? 'Cluster average, live' : 'No metrics source') : undefined} />
        <StatCard loading={loading} name="Users" icon={UsersIcon} value={stats ? num(stats.total_users) : '—'}
          hint={stats ? `${num(stats.active_users)} signed in within 30 days` : undefined} />
      </div>

      {/* Traffic + node health */}
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <ChartCard className="lg:col-span-2" title="Requests per hour" description={`Last 24 hours · ${num(hourlyTotal)} requests`}
          loading={loading} empty={hourlyTotal === 0} emptyText="No requests in the last 24 hours.">
          <AreaChart data={hourly} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
            <defs>
              <linearGradient id="dashReq" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={SERIES[0]} stopOpacity={0.35} />
                <stop offset="100%" stopColor={SERIES[0]} stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid {...gridProps} />
            <XAxis dataKey="t" {...axisProps} tickFormatter={(v) => hourLabel(v)} minTickGap={24} />
            <YAxis {...axisProps} allowDecimals={false} width={48} />
            <Tooltip cursor={{ stroke: 'currentColor', strokeOpacity: 0.2 }} content={<ChartTooltip labelFormat={(l) => hourLabel(l, true)} />} />
            <Area type="monotone" dataKey="requests" name="Requests" stroke={SERIES[0]} strokeWidth={2} fill="url(#dashReq)" isAnimationActive={false} />
          </AreaChart>
        </ChartCard>

        <Card className="flex flex-col">
          <CardHeader title="Node health"
            description={nodes.length ? (attention ? `${attention} of ${nodes.length} need attention` : `All ${nodes.length} nodes online`) : undefined}
            actions={can('monitoring.view') ? <Link to="/monitoring" className={linkCls}>Monitoring</Link> : undefined} />
          <div className="flex-1 px-4 pt-2 pb-4 sm:px-6">
            {loading ? (
              <div className="space-y-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-8 w-full" />)}</div>
            ) : nodes.length ? (
              <ul className="max-h-[260px] space-y-3 overflow-y-auto pr-1">
                {nodes.map((n) => (
                  <li key={n.name}>
                    <div className="mb-1 flex items-center justify-between gap-2 text-sm">
                      <span className="truncate font-medium text-slate-700 dark:text-slate-200">{n.name}</span>
                      <span className="flex shrink-0 items-center gap-2">
                        <span className="text-xs font-semibold text-slate-500 tabular-nums dark:text-slate-400" title="Health score">{n.score}</span>
                        <StatusBadge status={n.status} />
                      </span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                      <div className={cx('h-full rounded-full transition-[width] duration-500', scoreTone(n.score))} style={{ width: `${Math.max(2, n.score)}%` }} />
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState icon={ServerStackIcon} title="No servers yet" description="Add an Ollama node or provider to start serving models."
                action={can('servers.view') ? <Link to="/servers"><Button variant="soft">Go to Servers</Button></Link> : undefined} />
            )}
          </div>
        </Card>
      </div>

      {/* Tokens + models */}
      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <ChartCard title="Token usage" description={`Last 7 days · ${compact(weekTokens)} tokens`} loading={loading} empty={weekTokens === 0}
          emptyText="No token usage in the last 7 days."
          legend={<><LegendDot color={PROMPT_COLOR} label="Prompt" /><LegendDot color={COMPLETION_COLOR} label="Completion" /></>}>
          <BarChart data={daily} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
            <CartesianGrid {...gridProps} />
            <XAxis dataKey="t" {...axisProps} tickFormatter={shortDay} />
            <YAxis {...axisProps} tickFormatter={(v) => compact(v)} width={48} />
            <Tooltip cursor={cursorFill} content={<ChartTooltip labelFormat={shortDay} />} />
            <Bar dataKey="prompt" name="Prompt" stackId="t" fill={PROMPT_COLOR} isAnimationActive={false} />
            <Bar dataKey="completion" name="Completion" stackId="t" fill={COMPLETION_COLOR} radius={[3, 3, 0, 0]} isAnimationActive={false} />
          </BarChart>
        </ChartCard>

        <ChartCard title="Model usage" description="Top models by requests, all time" loading={loading} empty={!models.length}
          emptyText="No model usage yet." height={Math.max(240, models.length * 34)}>
          <BarChart data={models} layout="vertical" margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
            <CartesianGrid {...gridProps} vertical horizontal={false} />
            <XAxis type="number" {...axisProps} allowDecimals={false} tickFormatter={(v) => compact(v)} />
            <YAxis type="category" dataKey="model" {...axisProps} width={130} tickFormatter={(v: string) => (v.length > 20 ? `${v.slice(0, 19)}…` : v)} />
            <Tooltip cursor={cursorFill} content={<ChartTooltip />} />
            <Bar dataKey="requests" name="Requests" radius={[0, 4, 4, 0]} barSize={18} isAnimationActive={false}>
              {models.map((m, i) => <Cell key={m.model} fill={SERIES[i % SERIES.length]} />)}
            </Bar>
          </BarChart>
        </ChartCard>
      </div>

      {/* Recent activity */}
      {canLogs && (
        <Card className="mt-6">
          <CardHeader title="Recent gateway activity" description="The latest calls through the API gateway"
            actions={<Link to="/api-logs" className={linkCls}>All logs</Link>} />
          <DataTable<ApiLogRow>
            dense
            columns={activityColumns}
            rows={logs.data?.rows}
            rowKey={(r) => r.id}
            loading={logs.isLoading}
            error={logs.error}
            onRetry={() => logs.refetch()}
            empty={<EmptyState icon={SignalIcon} title="No gateway calls yet" description="Calls made with an API key show up here as they happen."
              action={can('apikeys.manage') ? <Link to="/api-keys"><Button variant="soft">Create an API key</Button></Link> : undefined} />}
          />
        </Card>
      )}
    </>
  );
}

const activityColumns: Column<ApiLogRow>[] = [
  {
    key: 'endpoint', header: 'Request',
    cell: (r) => (
      <div className="min-w-0">
        <div className="truncate font-mono text-xs text-slate-900 dark:text-slate-100">
          <span className="text-slate-400 dark:text-slate-500">{r.method}</span> {r.endpoint}
        </div>
        <div className="truncate text-xs text-slate-500 dark:text-slate-400">{r.key_name ?? 'No key'}</div>
      </div>
    ),
  },
  { key: 'model', header: 'Model', hideBelow: 'md', cell: (r) => <span className="truncate text-sm">{r.model ?? '—'}</span> },
  { key: 'status', header: 'Status', cell: (r) => <Badge tone={r.status < 300 ? 'green' : r.status < 500 ? 'amber' : 'red'}>{r.status}</Badge> },
  { key: 'tokens', header: 'Tokens', hideBelow: 'lg', align: 'right', cell: (r) => <span className="tabular-nums">{num(r.total_tokens)}</span> },
  { key: 'ms', header: 'Latency', hideBelow: 'sm', align: 'right', cell: (r) => <span className="tabular-nums">{ms(r.processing_ms)}</span> },
  { key: 'at', header: 'When', align: 'right', cell: (r) => <span className="text-xs whitespace-nowrap text-slate-500 dark:text-slate-400">{ago(r.created_at)}</span> },
];
