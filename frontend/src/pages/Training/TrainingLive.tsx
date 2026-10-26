import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { KeyIcon } from '@heroicons/react/24/outline';
import { Alert, Badge, Button, Card, CardHeader, CodeBlock, EmptyState, ErrorState, Meter, Modal, SkeletonRows, StatusBadge, useToast } from '../../components/ui';
import { get, post } from '../../lib/api';
import { ago, dateTime, num } from '../../lib/format';
import { useAuth } from '../../lib/auth';

interface Live {
  loss?: number; lr?: number; sec_per_step?: number; cpu_pct?: number; ram_pct?: number; gpu_pct?: number; vram_pct?: number;
  vram_used_mb?: number; vram_total_mb?: number; gpu_temp_c?: number; gpu_power_w?: number; gpu_name?: string; host?: string;
  metrics?: Record<string, Record<string, number>>;
}
interface LiveJob {
  id: number; name: string; base_model: string; method: string; status: string; dataset_name: string | null; server_name: string | null;
  progress_pct: string | null; phase: string | null; current_step: string | null; total_steps: string | null; epoch: string | null;
  eta_seconds: string | null; started_at: string | null; finished_at: string | null; last_report_at: string | null;
  live: Live | null; log_tail: string; has_token: boolean; stale: boolean;
}
interface Point { at: string; step: string | null; loss: string | null; lr: string | null; cpu_pct: string | null; gpu_pct: string | null; vram_pct: string | null; gpu_temp: string | null }

export function duration(s: number | null | undefined) {
  if (s == null || !isFinite(s)) return '—';
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = Math.floor(s % 60);
  return h ? `${h}h ${m}m` : m ? `${m}m ${sec}s` : `${sec}s`;
}

/** Progress bar + ETA for the jobs table. */
export function JobProgress({ job }: { job: { status: string; progress_pct?: string | number | null; eta_seconds?: string | number | null; phase?: string | null } }) {
  if (job.progress_pct == null && job.status !== 'running') return <span className="text-xs text-slate-400">—</span>;
  const p = Number(job.progress_pct ?? 0);
  return (
    <div className="w-36">
      <div className="flex justify-between text-xs"><span className="font-medium tabular-nums">{p.toFixed(1)}%</span>
        {job.status === 'running' && <span className="text-slate-500 dark:text-slate-400">{job.eta_seconds != null ? `${duration(Number(job.eta_seconds))} left` : job.phase ?? ''}</span>}</div>
      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
        <div className={`h-full rounded-full ${job.status === 'failed' ? 'bg-red-500' : job.status === 'completed' ? 'bg-green-500' : 'bg-brand-600'}`} style={{ width: `${p}%` }} />
      </div>
    </div>
  );
}

export default function TrainingLive({ id, onClose }: { id: number; onClose: () => void }) {
  const toast = useToast();
  const manage = useAuth().can('models.manage');
  const q = useQuery({
    queryKey: ['training-live', id], queryFn: () => get<{ job: LiveJob; points: Point[] }>(`/training/${id}/live`),
    refetchInterval: (query) => (query.state.data?.job.status === 'running' ? 5000 : false),
  });
  const [token, setToken] = useState<{ token: string; url: string } | null>(null);
  const issue = useMutation({
    mutationFn: () => post<{ token: string; url: string }>(`/training/${id}/report-token`),
    onSuccess: setToken, onError: (e) => toast.error(e),
  });
  const logRef = useRef<HTMLPreElement>(null);
  useEffect(() => { logRef.current?.scrollTo({ top: logRef.current.scrollHeight }); }, [q.data?.job.log_tail]);

  const j = q.data?.job;
  const L = j?.live ?? {};
  const pts = (q.data?.points ?? []).map((p) => ({
    t: new Date(p.at.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
    step: p.step != null ? Number(p.step) : null, loss: p.loss != null ? Number(p.loss) : null,
    gpu: p.gpu_pct != null ? Number(p.gpu_pct) : null, vram: p.vram_pct != null ? Number(p.vram_pct) : null, cpu: p.cpu_pct != null ? Number(p.cpu_pct) : null,
  }));
  const lossPts = pts.filter((p) => p.loss != null);
  const elapsed = j?.started_at ? ((j.finished_at ? Date.parse(j.finished_at.replace(' ', 'T') + 'Z') : Date.now()) - Date.parse(j.started_at.replace(' ', 'T') + 'Z')) / 1000 : null;
  const eta = j?.eta_seconds != null ? Number(j.eta_seconds) : null;

  return (
    <Modal open onClose={onClose} size="xl"
      title={j ? <span className="flex flex-wrap items-center gap-2">{j.name}<StatusBadge status={j.status === 'kit_ready' ? 'ready' : j.status} />{j.stale && <Badge tone="amber">no report for 3+ min</Badge>}</span> : 'Training job'}
      description={j ? `${j.base_model}${j.dataset_name ? ` · ${j.dataset_name}` : ''}${j.server_name ? ` · on ${j.server_name}` : L.host ? ` · on ${L.host}` : ''}` : undefined}>
      {q.isLoading ? <SkeletonRows rows={6} cols={3} /> : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : j && (
        <div className="space-y-4">
          <Card padded>
            <div className="flex flex-wrap items-end justify-between gap-2">
              <div>
                <div className="text-3xl font-semibold tabular-nums text-slate-900 dark:text-white">{Number(j.progress_pct ?? 0).toFixed(1)}%</div>
                <div className="text-sm text-slate-500 dark:text-slate-400">{j.phase ?? j.status}{j.current_step ? ` · step ${num(j.current_step)} of ${num(j.total_steps)}` : ''}{j.epoch ? ` · epoch ${Number(j.epoch).toFixed(2)}` : ''}</div>
              </div>
              <div className="text-right text-sm text-slate-500 dark:text-slate-400">
                {j.status === 'running' && <div><b className="text-slate-900 dark:text-white">{duration(eta)}</b> left{eta != null ? ` · done ≈ ${new Date(Date.now() + eta * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}</div>}
                <div>{elapsed != null ? `${duration(elapsed)} elapsed` : 'not started'}{j.last_report_at ? ` · updated ${ago(j.last_report_at)}` : ''}</div>
              </div>
            </div>
            <div className="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
              <div className={`h-full rounded-full transition-all ${j.status === 'failed' ? 'bg-red-500' : j.status === 'completed' ? 'bg-green-500' : 'bg-brand-600'}`} style={{ width: `${Number(j.progress_pct ?? 0)}%` }} />
            </div>
          </Card>

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Fig label="Loss" value={L.loss != null ? L.loss.toFixed(4) : '—'} hint={L.lr != null ? `lr ${L.lr.toExponential(2)}` : undefined} />
            <Fig label="Speed" value={L.sec_per_step != null ? `${L.sec_per_step.toFixed(1)} s/step` : '—'} />
            <Fig label="GPU" value={L.gpu_pct != null ? `${Math.round(L.gpu_pct)}%` : '—'} hint={[L.gpu_name, L.gpu_temp_c != null ? `${Math.round(L.gpu_temp_c)} °C` : null, L.gpu_power_w != null ? `${Math.round(L.gpu_power_w)} W` : null].filter(Boolean).join(' · ') || undefined} />
            <Fig label="VRAM" value={L.vram_pct != null ? `${Math.round(L.vram_pct)}%` : '—'} hint={L.vram_used_mb != null ? `${(L.vram_used_mb / 1024).toFixed(1)} of ${((L.vram_total_mb ?? 0) / 1024).toFixed(1)} GB` : undefined} />
          </div>

          {L.metrics && Object.keys(L.metrics).length > 0 && (
            <Card>
              <CardHeader title="Evaluation" />
              <table className="w-full text-sm">
                <thead className="text-left text-xs text-slate-500 dark:text-slate-400"><tr><th className="px-4 py-2 font-medium sm:px-6">Run</th>{Object.keys(Object.values(L.metrics)[0]).map((k) => <th key={k} className="px-3 py-2 text-right font-medium">{k.replace(/_/g, ' ')}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-100 dark:divide-white/5">{Object.entries(L.metrics).map(([run, m]) => (
                  <tr key={run}><td className="px-4 py-2 font-medium sm:px-6">{run}</td>{Object.keys(Object.values(L.metrics!)[0]).map((k) => <td key={k} className="px-3 py-2 text-right tabular-nums">{m[k] ?? '—'}</td>)}</tr>
                ))}</tbody>
              </table>
            </Card>
          )}

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Training loss" />
              {lossPts.length > 1 ? <Chart data={lossPts} lines={[['loss', 'Loss', '#4f46e5']]} /> : <p className="p-6 text-center text-sm text-slate-500 dark:text-slate-400">Appears after the first logged steps.</p>}
            </Card>
            <Card>
              <CardHeader title="Machine" />
              {pts.length > 1 ? <Chart data={pts} lines={[['gpu', 'GPU %', '#16a34a'], ['vram', 'VRAM %', '#f59e0b'], ['cpu', 'CPU %', '#0ea5e9']]} max={100} /> : (
                <div className="space-y-3 p-4 sm:px-6">
                  <Meter value={L.gpu_pct ?? 0} label="GPU" /><Meter value={L.vram_pct ?? 0} label="VRAM" /><Meter value={L.cpu_pct ?? 0} label="CPU" />
                </div>
              )}
            </Card>
          </div>

          <Card>
            <CardHeader title="Log" description="Latest 300 lines, updates every 5 seconds while running." />
            {j.log_tail ? <pre ref={logRef} className="max-h-80 overflow-auto bg-slate-950 px-4 py-3 font-mono text-xs/5 whitespace-pre-wrap text-slate-200">{j.log_tail}</pre>
              : <EmptyState title="No log yet" description="Lines appear once the runner starts reporting." />}
          </Card>

          <Card>
            <CardHeader title="Report progress from a training machine" description={j.has_token ? 'This job has a report token. Creating a new one replaces it.' : 'Create a token, then run the reporter next to your training run.'}
              actions={manage && <Button size="sm" icon={KeyIcon} loading={issue.isPending} onClick={() => issue.mutate()}>Create report token</Button>} />
            <div className="space-y-2 p-4 text-sm sm:px-6">
              {token ? (
                <>
                  <Alert tone="warning" title="Shown once">Anyone with this token can post progress for this job.</Alert>
                  <CodeBlock className="whitespace-pre-wrap break-all">{`curl -fsSLO ${window.location.origin}/node/aistudio-train-report.py
python aistudio-train-report.py --url ${token.url} \\
  --token ${token.token} --log train.log`}</CodeBlock>
                </>
              ) : <p className="text-slate-500 dark:text-slate-400">The reporter follows your training log and sends step, ETA, loss, evaluation results, GPU/CPU load and new log lines every 10 seconds.</p>}
              {j.started_at && <p className="text-xs text-slate-500 dark:text-slate-400">Started {dateTime(j.started_at)}{j.finished_at ? ` · finished ${dateTime(j.finished_at)}` : ''}</p>}
            </div>
          </Card>
        </div>
      )}
    </Modal>
  );
}

function Fig({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
      <div className="text-xs text-slate-500 dark:text-slate-400">{label}</div>
      <div className="mt-1 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{value}</div>
      {hint && <div className="truncate text-xs text-slate-500 dark:text-slate-400" title={hint}>{hint}</div>}
    </div>
  );
}

function Chart({ data, lines, max }: { data: Record<string, unknown>[]; lines: [string, string, string][]; max?: number }) {
  return (
    <div className="h-48 p-4 text-slate-400 sm:px-6 dark:text-slate-500">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 5, right: 5, left: -20, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.25} />
          <XAxis dataKey="t" tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" minTickGap={30} />
          <YAxis domain={max ? [0, max] : ['auto', 'auto']} tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
          <Tooltip contentStyle={{ fontSize: 12, borderRadius: 6 }} />
          {lines.map(([k, name, color]) => <Line key={k} type="monotone" dataKey={k} name={name} stroke={color} strokeWidth={2} dot={false} isAnimationActive={false} connectNulls />)}
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
