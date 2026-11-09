import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { HeartIcon, PencilSquareIcon, PowerIcon, ArrowUpTrayIcon, ArrowPathRoundedSquareIcon, CpuChipIcon } from '@heroicons/react/24/outline';
import {
  Badge, Button, Card, CardHeader, DescriptionList, EmptyState, ErrorState, Modal, SkeletonRows, StatusBadge, Tabs, useToast,
} from '../../components/ui';
import { ServerLogs, ServerUsage } from './ServerUsage';
import { get, post } from '../../lib/api';
import { ago, bytes, dateTime, parseTs } from '../../lib/format';
import { useAuth } from '../../lib/auth';
import { ACCELERATORS, AVAILABILITY, DEPLOYMENTS, HealthScore, KINDS, Utilization, type ControlAction, type HealthRow, type MetricRow, type Server } from './shared';

interface NodeModel { name: string; size?: number; details?: { parameter_size?: string; quantization_level?: string; family?: string } }

/** Chart tooltip that follows the theme (Recharts draws a white box otherwise). */
const TOOLTIP = { fontSize: 12, borderRadius: 6, background: 'var(--tooltip-bg, #fff)', color: 'var(--tooltip-fg, #0f172a)', border: '1px solid var(--tooltip-border, #e2e8f0)' };

export default function ServerDetail({ id, onClose, onEdit, onControl }: {
  id: number; onClose: () => void; onEdit: (s: Server) => void; onControl: (s: Server, a: ControlAction) => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const manage = useAuth().can('servers.manage');
  const [tab, setTab] = useState<'overview' | 'usage' | 'logs'>('overview');
  const q = useQuery({ queryKey: ['server', id], queryFn: () => get<Server & { health_history: HealthRow[] }>(`/servers/${id}`),
    refetchInterval: (query) => (query.state.data?.pending_control ? 5_000 : false) }); // watch a command until the agent reports
  const s = q.data;
  const models = useQuery({
    queryKey: ['server-models', id],
    queryFn: () => get<NodeModel[]>(`/servers/${id}/models`),
    enabled: !!s && s.status !== 'offline',
    retry: false,
  });
  const health = useMutation({
    mutationFn: () => post<{ status: string; health_score: number }>(`/servers/${id}/health`),
    onSuccess: (r) => {
      toast.success('Health check complete', r ? `Status ${r.status}, score ${r.health_score}/100` : undefined);
      qc.invalidateQueries({ queryKey: ['server', id] });
      qc.invalidateQueries({ queryKey: ['server-models', id] });
      qc.invalidateQueries({ queryKey: ['servers'] });
    },
    onError: (e) => toast.error(e),
  });

  const kind = s?.kind ?? 'ollama';
  const ollama = kind === 'ollama';
  const selfHosted = !KINDS[kind].cloud;
  const metrics = useQuery({
    queryKey: ['server-metrics', id], queryFn: () => get<MetricRow[]>(`/servers/${id}/metrics?hours=24`),
    enabled: !!s && selfHosted, refetchInterval: 60_000,
  });
  const metricData = (metrics.data ?? []).map((m) => ({
    t: parseTs(m.recorded_at)?.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) ?? '',
    gpu: m.gpu_util_pct, cpu: m.cpu_util_pct,
    vram: s?.gpu_vram_gb && m.vram_used_gb != null ? Math.round((m.vram_used_gb / Number(s.gpu_vram_gb)) * 100) : null,
  }));
  const history = [...(s?.health_history ?? [])].reverse().map((h) => ({
    t: parseTs(h.checked_at)?.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) ?? '',
    score: Number(h.health_score),
    latency: h.latency_ms ?? 0,
  }));
  const last = s?.health_history?.[0];

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title={s ? <span className="flex flex-wrap items-center gap-2">{s.name} <StatusBadge status={s.status} />{s.availability && <Badge tone={AVAILABILITY[s.availability].tone}>{AVAILABILITY[s.availability].label}</Badge>}{!ollama && <Badge tone={KINDS[kind].tone}>{KINDS[kind].label}</Badge>}</span> : 'Server'}
      description={s?.api_url || (s && KINDS[kind].base)}
      footer={s && (
        <div className="flex w-full flex-wrap justify-end gap-2">
          {manage && ollama && <>
            <Button size="sm" icon={ArrowPathRoundedSquareIcon} onClick={() => onControl(s, 'restart')}>Restart</Button>
            <Button size="sm" icon={ArrowUpTrayIcon} onClick={() => onControl(s, 'update')}>Update Ollama</Button>
            <Button size="sm" variant="danger" icon={PowerIcon} onClick={() => onControl(s, 'shutdown')}>Shut down</Button>
          </>}
          {manage && <Button size="sm" icon={PencilSquareIcon} onClick={() => onEdit(s)}>Edit</Button>}
          {manage && <Button size="sm" variant="primary" icon={HeartIcon} loading={health.isPending} onClick={() => health.mutate()}>Health check</Button>}
        </div>
      )}
    >
      {s && <Tabs className="mb-4" value={tab} onChange={(k) => setTab(k as typeof tab)} tabs={[{ key: 'overview', label: 'Overview' }, { key: 'usage', label: 'Usage' }, { key: 'logs', label: 'Logs' }]} />}
      {s && tab === 'usage' && <ServerUsage id={id} />}
      {s && tab === 'logs' && <ServerLogs id={id} />}
      {q.isLoading ? <SkeletonRows rows={6} cols={2} /> : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : s && tab === 'overview' && (
        <div className="space-y-4">
          {s.is_enabled === false && (
            <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-600/20 ring-inset dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
              Disabled{s.disabled_at ? ` since ${s.disabled_at}` : ''}{s.disabled_reason ? ` — ${s.disabled_reason}` : ''}. It gets no new chat, gateway, knowledge or training work; health checks continue. Enable it from the Servers list.
            </p>
          )}
          {last?.error && s.status !== 'online' && (
            <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">Last check: {last.error}</p>
          )}
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Overview" />
              <DescriptionList items={[
                { label: 'Health score', value: <><HealthScore score={s.health_score} /> <span className="text-slate-500 dark:text-slate-400">/ 100</span></> },
                { label: 'Last heartbeat', value: s.last_heartbeat_at ? `${ago(s.last_heartbeat_at)} (${dateTime(s.last_heartbeat_at)})` : 'never' },
                { label: 'Latency (last check)', value: last?.latency_ms != null ? `${last.latency_ms} ms` : '—' },
                ...(selfHosted ? [
                  { label: ollama ? 'Ollama version' : 'Engine', value: s.ollama_version ?? KINDS[kind].label },
                  { label: 'Deployment', value: [s.deployment ? DEPLOYMENTS[s.deployment] : '—', s.cloud_provider, s.region, s.container_image].filter(Boolean).join(' · ') },
                  { label: 'Accelerator', value: s.accelerator ? `${ACCELERATORS[s.accelerator]}${s.accelerator === 'rocm' && s.rocm_version ? ` ${s.rocm_version}` : ''}` : 'Not reported' },
                  ...(s.pending_control || s.control_result ? [{ label: 'Last command', value: s.pending_control
                    ? `${s.pending_control} — waiting for the node agent`
                    : <span className={s.control_result?.startsWith('ok') ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400'}>{s.control_result}{s.control_result_at ? ` (${ago(s.control_result_at)})` : ''}</span> }] : []),
                  { label: 'Agent report', value: s.metrics_at ? `${ago(s.metrics_at)}${s.metrics_fresh ? '' : ' (stale)'}` : 'No agent connected' },
                  ...(s.max_concurrency ? [{ label: 'Max concurrent requests', value: String(s.max_concurrency) }] : []),
                  { label: 'Host', value: [s.hostname, s.ip_address].filter(Boolean).join(' · ') || '—' },
                  { label: 'OS', value: s.os ?? '—' },
                  { label: 'Local node', value: Number(s.is_local) === 1 ? 'Yes' : 'No' },
                ] : []),
                { label: 'Auth', value: s.has_token ? 'Key stored' : 'None' },
                ...(s.description ? [{ label: 'Notes', value: s.description }] : []),
              ]} />
            </Card>
            <Card>
              <CardHeader title="Hardware & health history" />
              <div className="space-y-4 p-4 sm:px-6">
                {selfHosted && (
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <Spec label="CPU" value={s.cpu_cores ? `${s.cpu_cores} cores` : '—'} />
                    <Spec label="RAM" value={s.ram_gb ? `${Number(s.ram_gb)} GB` : '—'} />
                    <Spec label="Storage" value={s.storage_gb ? `${Number(s.storage_gb)} GB` : '—'} />
                    <Spec label="GPU" value={s.gpu_model ? `${Number(s.gpu_count) > 1 ? `${s.gpu_count} × ` : ''}${s.gpu_model}` : 'CPU-only'} />
                    <Spec label="VRAM" value={s.gpu_vram_gb ? `${Number(s.gpu_vram_gb)} GB` : '—'} />
                    {s.accelerator === 'rocm'
                      ? <Spec label="ROCm version" value={s.rocm_version || '—'} />
                      : <><Spec label="CUDA cores" value={s.gpu_model ? (s.cuda_cores ? Number(s.cuda_cores).toLocaleString() : 'Unknown model') : '—'} />
                        <Spec label="CUDA version" value={s.cuda_version || '—'} /></>}
                  </div>
                )}
                {history.length > 1 ? (
                  <div className="h-44 text-slate-400 dark:text-slate-500">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={history} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.25} />
                        <XAxis dataKey="t" tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                        <YAxis domain={[0, 100]} tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                        <Tooltip contentStyle={TOOLTIP} labelStyle={{ color: 'inherit' }} />
                        <Line type="monotone" dataKey="score" name="Health" stroke="#4f46e5" strokeWidth={2} dot={false} isAnimationActive={false} />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                ) : (
                  <p className="py-6 text-center text-sm text-slate-500 dark:text-slate-400">Not enough data yet. Run a few health checks.</p>
                )}
              </div>
            </Card>
          </div>
          {selfHosted && (
            <Card>
              <CardHeader title="Utilization" description={s.metrics_fresh ? `Reported by the node agent ${ago(s.metrics_at!)}` : 'Install the node agent (Servers → Connect a node) to report GPU, VRAM, CPU, RAM and disk use.'} />
              <div className="space-y-4 p-4 sm:px-6">
                <Utilization s={s} />
                {metricData.length > 1 ? (
                  <div className="h-44 text-slate-400 dark:text-slate-500">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={metricData} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.25} />
                        <XAxis dataKey="t" tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" minTickGap={30} />
                        <YAxis domain={[0, 100]} unit="%" tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                        <Tooltip contentStyle={TOOLTIP} labelStyle={{ color: 'inherit' }} />
                        <Line type="monotone" dataKey="gpu" name="GPU %" stroke="#4f46e5" strokeWidth={2} dot={false} isAnimationActive={false} connectNulls />
                        <Line type="monotone" dataKey="vram" name="VRAM %" stroke="#d97706" strokeWidth={2} dot={false} isAnimationActive={false} connectNulls />
                        <Line type="monotone" dataKey="cpu" name="CPU %" stroke="#059669" strokeWidth={2} dot={false} isAnimationActive={false} connectNulls />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                ) : s.metrics_fresh ? <p className="text-sm text-slate-500 dark:text-slate-400">The 24-hour chart fills in as the agent reports, one sample a minute.</p> : null}
              </div>
            </Card>
          )}
          <Card>
            <CardHeader title={ollama ? 'Installed models' : 'Available models'} description={models.data ? `${models.data.length} on this ${ollama ? 'node' : 'provider'}` : undefined} />
            {s.status === 'offline' ? (
              <EmptyState icon={CpuChipIcon} title="Node is offline" description="Models cannot be listed until the node answers a health check." />
            ) : models.isLoading ? <SkeletonRows rows={4} cols={3} /> : models.error ? (
              <ErrorState error={models.error} onRetry={() => models.refetch()} />
            ) : models.data?.length ? (
              <ul className="divide-y divide-slate-100 dark:divide-white/5">
                {models.data.map((m) => (
                  <li key={m.name} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm sm:px-6">
                    <span className="truncate font-mono font-medium text-slate-800 dark:text-slate-100">{m.name}</span>
                    <span className="flex shrink-0 gap-3 text-xs text-slate-500 dark:text-slate-400">
                      {m.details?.parameter_size && <span>{m.details.parameter_size}</span>}
                      {m.details?.quantization_level && <span className="hidden sm:inline">{m.details.quantization_level}</span>}
                      {m.size != null && <span>{bytes(m.size)}</span>}
                    </span>
                  </li>
                ))}
              </ul>
            ) : <EmptyState icon={CpuChipIcon} title="No models" description={ollama ? 'No models are installed on this node yet. Install one from the Models page.' : 'The provider returned no models.'} />}
          </Card>
        </div>
      )}
    </Modal>
  );
}

function Spec({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
      <div className="text-xs text-slate-500 dark:text-slate-400">{label}</div>
      <div className="mt-1 text-sm font-semibold break-words text-slate-900 dark:text-white" title={value}>{value}</div>
    </div>
  );
}
