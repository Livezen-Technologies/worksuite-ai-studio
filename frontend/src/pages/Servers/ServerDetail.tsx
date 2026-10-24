import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { HeartIcon, PencilSquareIcon, PowerIcon, ArrowUpTrayIcon, ArrowPathRoundedSquareIcon, CpuChipIcon } from '@heroicons/react/24/outline';
import {
  Badge, Button, Card, CardHeader, DescriptionList, EmptyState, ErrorState, Modal, SkeletonRows, StatusBadge, useToast,
} from '../../components/ui';
import { get, post } from '../../lib/api';
import { ago, bytes, dateTime, parseTs } from '../../lib/format';
import { useAuth } from '../../lib/auth';
import { HealthScore, KINDS, type ControlAction, type HealthRow, type Server } from './shared';

interface NodeModel { name: string; size?: number; details?: { parameter_size?: string; quantization_level?: string; family?: string } }

export default function ServerDetail({ id, onClose, onEdit, onControl }: {
  id: number; onClose: () => void; onEdit: (s: Server) => void; onControl: (s: Server, a: ControlAction) => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const manage = useAuth().can('servers.manage');
  const q = useQuery({ queryKey: ['server', id], queryFn: () => get<Server & { health_history: HealthRow[] }>(`/servers/${id}`) });
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
      title={s ? <span className="flex flex-wrap items-center gap-2">{s.name} <StatusBadge status={s.status} />{!ollama && <Badge tone={KINDS[kind].tone}>{KINDS[kind].label}</Badge>}</span> : 'Server'}
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
      {q.isLoading ? <SkeletonRows rows={6} cols={2} /> : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : s && (
        <div className="space-y-4">
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
                ...(ollama ? [
                  { label: 'Ollama version', value: s.ollama_version ?? '—' },
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
                {ollama && (
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Spec label="CPU" value={s.cpu_cores ? `${s.cpu_cores} cores` : '—'} />
                    <Spec label="RAM" value={s.ram_gb ? `${Number(s.ram_gb)} GB` : '—'} />
                    <Spec label="Storage" value={s.storage_gb ? `${Number(s.storage_gb)} GB` : '—'} />
                    <Spec label="GPU" value={s.gpu_model ? `${s.gpu_model}${s.cuda_version ? ` · CUDA ${s.cuda_version}` : ''}` : 'CPU-only'} />
                  </div>
                )}
                {history.length > 1 ? (
                  <div className="h-44 text-slate-400 dark:text-slate-500">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={history} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.25} />
                        <XAxis dataKey="t" tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                        <YAxis domain={[0, 100]} tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                        <Tooltip contentStyle={{ fontSize: 12, borderRadius: 6 }} />
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
      <div className="mt-1 truncate text-sm font-semibold text-slate-900 dark:text-white" title={value}>{value}</div>
    </div>
  );
}
