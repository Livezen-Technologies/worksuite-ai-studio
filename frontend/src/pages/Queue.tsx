import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowPathIcon, CheckCircleIcon, ClockIcon, PlayIcon, PlusIcon, QueueListIcon, XCircleIcon, NoSymbolIcon, BoltIcon,
} from '@heroicons/react/24/outline';
import {
  Button, Card, CodeBlock, ConfirmDialog, DataTable, EmptyState, FormModal, IconButton, Input, Modal, PageHeader, Pagination,
  Select, StatCard, StatusBadge, Tabs, Textarea, Toggle, Toolbar, useToast, type Column,
} from '../components/ui';
import { ApiError, get, post, put, type Paged } from '../lib/api';
import { ago, dateTime } from '../lib/format';
import { useAuth } from '../lib/auth';

interface Job {
  id: number; type: string; payload: string | null; status: string; priority: number; attempts: number; max_attempts: number;
  result: string | null; error: string | null; available_at: string | null; started_at: string | null; finished_at: string | null; created_at: string | null;
}
type Stats = Record<'pending' | 'running' | 'completed' | 'failed' | 'cancelled', number>;
interface Cron {
  id: number; key: string; name: string; description: string | null; handler: string; schedule: string; enabled: number | string;
  last_run_at: string | null; last_status: string | null; last_output: string | null; next_run_at: string | null;
}

const JOB_TYPES = ['noop', 'health_check', 'sync_models', 'pull_model', 'generate_report', 'cleanup_logs'];
const STATUSES = ['pending', 'running', 'completed', 'failed', 'cancelled'];
const SCHEDULES = [
  { value: 'every_minute', label: 'Every minute' }, { value: 'every_5m', label: 'Every 5 minutes' }, { value: 'every_15m', label: 'Every 15 minutes' },
  { value: 'hourly', label: 'Hourly' }, { value: 'daily', label: 'Daily' }, { value: 'weekly', label: 'Weekly' },
];

export default function Queue() {
  const [tab, setTab] = useState('jobs');
  const stats = useQuery({ queryKey: ['queue', 'stats-only'], queryFn: () => get<Paged<Job> & { stats: Stats }>('/queue', { per_page: 1 }), refetchInterval: 10000 });
  const cron = useQuery({ queryKey: ['cron'], queryFn: () => get<Cron[]>('/cron') });
  return (
    <>
      <PageHeader title="Queue & scheduler" description="Background jobs and scheduled maintenance." />
      <Tabs
        className="mb-6"
        value={tab}
        onChange={setTab}
        tabs={[
          { key: 'jobs', label: 'Jobs', count: stats.data ? stats.data.stats.pending + stats.data.stats.running : undefined },
          { key: 'cron', label: 'Scheduled', count: cron.data?.length },
        ]}
      />
      {tab === 'jobs' ? <Jobs /> : <Scheduled />}
    </>
  );
}

function Jobs() {
  const qc = useQueryClient();
  const toast = useToast();
  const manage = useAuth().can('queues.manage');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [enqueue, setEnqueue] = useState(false);
  const [cancelling, setCancelling] = useState<Job | null>(null);
  const [viewing, setViewing] = useState<Job | null>(null);

  const q = useQuery({
    queryKey: ['queue', status, type, page],
    queryFn: () => get<Paged<Job> & { stats: Stats }>('/queue', { status, type, page }),
    refetchInterval: 5000,
  });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['queue'] });
  const work = useMutation({
    mutationFn: () => post<{ processed: number; failed: number }>('/queue/work'),
    onSuccess: (r) => { toast.success('Worker run complete', `${r.processed} processed, ${r.failed} failed`); invalidate(); },
    onError: (e) => toast.error(e),
  });
  const cancel = useMutation({
    mutationFn: (id: number) => post(`/queue/${id}/cancel`),
    onSuccess: () => { toast.success('Job cancelled'); setCancelling(null); invalidate(); },
    onError: (e) => toast.error(e),
  });
  const retry = useMutation({
    mutationFn: (id: number) => post(`/queue/${id}/retry`),
    onSuccess: () => { toast.success('Job requeued'); invalidate(); },
    onError: (e) => toast.error(e),
  });
  const s = q.data?.stats;
  const filter = (st: string) => { setStatus(status === st ? '' : st); setPage(1); };

  const columns: Column<Job>[] = [
    { key: 'id', header: 'ID', hideBelow: 'sm', cell: (j) => <span className="tabular-nums text-slate-500 dark:text-slate-400">#{j.id}</span> },
    { key: 'type', header: 'Type', cell: (j) => <code className="text-xs text-slate-800 dark:text-slate-200">{j.type}</code> },
    { key: 'status', header: 'Status', cell: (j) => <StatusBadge status={j.status} /> },
    { key: 'priority', header: 'Priority', hideBelow: 'lg', align: 'right', cell: (j) => j.priority },
    { key: 'att', header: 'Attempts', hideBelow: 'md', align: 'right', cell: (j) => `${j.attempts}/${j.max_attempts}` },
    { key: 'created', header: 'Created', hideBelow: 'md', cell: (j) => <span className="text-xs text-slate-500 dark:text-slate-400" title={dateTime(j.created_at)}>{ago(j.created_at)}</span> },
    { key: 'err', header: 'Error', hideBelow: 'lg', cell: (j) => j.error ? <span className="line-clamp-1 max-w-xs text-xs text-red-600 dark:text-red-400" title={j.error}>{j.error}</span> : null },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (j) => manage ? (
        <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
          {['failed', 'cancelled'].includes(j.status) && <IconButton label="Retry" icon={ArrowPathIcon} variant="ghost" size="sm" disabled={retry.isPending} onClick={() => retry.mutate(j.id)} />}
          {['pending', 'running'].includes(j.status) && <IconButton label="Cancel" icon={XCircleIcon} variant="ghost" size="sm" onClick={() => setCancelling(j)} />}
        </div>
      ) : null,
    },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        {([
          ['pending', 'Pending', ClockIcon], ['running', 'Running', BoltIcon], ['completed', 'Completed', CheckCircleIcon],
          ['failed', 'Failed', XCircleIcon], ['cancelled', 'Cancelled', NoSymbolIcon],
        ] as const).map(([k, label, icon]) => (
          <button key={k} type="button" onClick={() => filter(k)} className={`rounded-lg text-left focus-visible:outline-2 focus-visible:outline-brand-600 ${status === k ? 'ring-2 ring-brand-500 dark:ring-blue-400' : ''}`}>
            <StatCard name={label} value={s?.[k] ?? 0} icon={icon} loading={q.isLoading} />
          </button>
        ))}
      </div>
      <Card>
        <Toolbar right={manage && <>
          <Button icon={PlayIcon} loading={work.isPending} onClick={() => work.mutate()}>Run worker</Button>
          <Button variant="primary" icon={PlusIcon} onClick={() => setEnqueue(true)}>Enqueue job</Button>
        </>}>
          <Select aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} wrapperClassName="w-full sm:w-44"
            options={[{ value: '', label: 'All statuses' }, ...STATUSES.map((x) => ({ value: x, label: x[0].toUpperCase() + x.slice(1) }))]} />
          <Select aria-label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} wrapperClassName="w-full sm:w-44"
            options={[{ value: '', label: 'All types' }, ...JOB_TYPES.map((x) => ({ value: x, label: x }))]} />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={q.data?.rows}
          rowKey={(j) => j.id}
          loading={q.isLoading}
          error={q.error}
          onRetry={() => q.refetch()}
          onRowClick={setViewing}
          empty={<EmptyState icon={QueueListIcon} title={status || type ? 'No jobs match' : 'No jobs yet'}
            description={status || type ? 'Try another filter.' : 'Jobs appear here when the system or a person enqueues work.'}
            action={manage && !status && !type ? <Button variant="primary" icon={PlusIcon} onClick={() => setEnqueue(true)}>Enqueue a job</Button> : undefined} />}
        />
        {q.data && q.data.total > q.data.per_page && <Pagination page={page} perPage={q.data.per_page} total={q.data.total} onPage={setPage} />}
      </Card>

      {enqueue && <EnqueueModal onClose={() => setEnqueue(false)} onDone={invalidate} />}
      <ConfirmDialog
        open={!!cancelling}
        onClose={() => setCancelling(null)}
        onConfirm={() => cancelling && cancel.mutate(cancelling.id)}
        busy={cancel.isPending}
        title="Cancel this job?"
        message={<>Job #{cancelling?.id} ({cancelling?.type}) will not run. You can retry it later.</>}
        confirmLabel="Cancel job"
      />
      <Modal open={!!viewing} onClose={() => setViewing(null)} size="lg" title={viewing ? `Job #${viewing.id} · ${viewing.type}` : ''}
        footer={<Button onClick={() => setViewing(null)}>Close</Button>}>
        {viewing && (
          <div className="space-y-4 text-sm">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <Info label="Status" value={<StatusBadge status={viewing.status} />} />
              <Info label="Priority" value={viewing.priority} />
              <Info label="Attempts" value={`${viewing.attempts}/${viewing.max_attempts}`} />
              <Info label="Created" value={dateTime(viewing.created_at)} />
              <Info label="Started" value={dateTime(viewing.started_at)} />
              <Info label="Finished" value={dateTime(viewing.finished_at)} />
            </div>
            {viewing.error && <p className="rounded-md bg-red-50 px-3 py-2 text-red-700 dark:bg-red-500/10 dark:text-red-300">{viewing.error}</p>}
            <div><div className="mb-1 font-medium text-slate-700 dark:text-slate-300">Payload</div><CodeBlock>{pretty(viewing.payload)}</CodeBlock></div>
            {viewing.result && <div><div className="mb-1 font-medium text-slate-700 dark:text-slate-300">Result</div><CodeBlock>{pretty(viewing.result)}</CodeBlock></div>}
          </div>
        )}
      </Modal>
    </div>
  );
}

function EnqueueModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [type, setType] = useState('noop');
  const [priority, setPriority] = useState('5');
  const [payload, setPayload] = useState('{}');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const m = useMutation({
    mutationFn: () => {
      let p: unknown = {};
      try { p = payload.trim() ? JSON.parse(payload) : {}; } catch { throw new ApiError('The payload is not valid JSON.', 422, { payload: 'Enter valid JSON, for example {"days": 30}.' }); }
      return post<{ id: number }>('/queue', { type, priority: Number(priority), payload: p });
    },
    onSuccess: (r) => { toast.success('Job enqueued', `Job #${r.id}`); onDone(); onClose(); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });
  return (
    <FormModal open onClose={onClose} title="Enqueue a job" description="The job runs on the next worker pass, or straight away with Run worker."
      submitLabel="Enqueue" submitting={m.isPending} onSubmit={() => m.mutate()}>
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Job type" value={type} onChange={(e) => setType(e.target.value)} options={JOB_TYPES.map((x) => ({ value: x, label: x }))} error={errors.type} />
          <Input label="Priority" type="number" min={1} max={10} value={priority} onChange={(e) => setPriority(e.target.value)} hint="1 runs first, 10 last." />
        </div>
        <Textarea label="Payload (JSON)" rows={4} className="font-mono" value={payload} onChange={(e) => setPayload(e.target.value)} error={errors.payload}
          hint={type === 'pull_model' ? 'e.g. {"server_id": 1, "name": "llama3.2"}' : type === 'cleanup_logs' ? 'e.g. {"days": 30}' : 'Optional.'} />
      </div>
    </FormModal>
  );
}

function Scheduled() {
  const qc = useQueryClient();
  const toast = useToast();
  const manage = useAuth().can('queues.manage');
  const q = useQuery({ queryKey: ['cron'], queryFn: () => get<Cron[]>('/cron') });
  const [running, setRunning] = useState<number | null>(null);
  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { enabled?: boolean; schedule?: string } }) => put<Cron>(`/cron/${id}`, body),
    onSuccess: (_r, v) => { toast.success(v.body.schedule ? 'Schedule updated' : v.body.enabled ? 'Job enabled' : 'Job paused'); qc.invalidateQueries({ queryKey: ['cron'] }); },
    onError: (e) => toast.error(e),
  });
  const run = useMutation({
    mutationFn: (id: number) => { setRunning(id); return post<{ ok: boolean; error?: string }>(`/cron/${id}/run`); },
    onSuccess: (r) => { if (r.ok) toast.success('Job ran'); else toast.error('Job ran with errors', r.error); qc.invalidateQueries({ queryKey: ['cron'] }); },
    onError: (e) => toast.error(e),
    onSettled: () => setRunning(null),
  });

  const columns: Column<Cron>[] = [
    {
      key: 'name', header: 'Job', cell: (c) => (
        <div className="min-w-0">
          <div className="font-medium text-slate-900 dark:text-white">{c.name}</div>
          <div className="truncate text-xs text-slate-500 dark:text-slate-400">{c.description}</div>
        </div>
      ),
    },
    {
      key: 'schedule', header: 'Schedule', cell: (c) => (
        <Select aria-label={`Schedule for ${c.name}`} value={c.schedule} disabled={!manage || update.isPending} wrapperClassName="w-40"
          onChange={(e) => update.mutate({ id: c.id, body: { schedule: e.target.value } })}
          options={SCHEDULES.some((s) => s.value === c.schedule) ? SCHEDULES : [...SCHEDULES, { value: c.schedule, label: c.schedule }]} />
      ),
    },
    {
      key: 'last', header: 'Last run', hideBelow: 'md', cell: (c) => c.last_run_at ? (
        <div className="flex items-center gap-2 text-xs" title={c.last_output ?? undefined}>
          <StatusBadge status={c.last_status === 'ok' ? 'success' : 'failed'} label={c.last_status ?? undefined} />
          <span className="text-slate-500 dark:text-slate-400">{ago(c.last_run_at)}</span>
        </div>
      ) : <span className="text-xs text-slate-500 dark:text-slate-400">never</span>,
    },
    { key: 'next', header: 'Next run', hideBelow: 'lg', cell: (c) => <span className="text-xs text-slate-500 dark:text-slate-400">{Number(c.enabled) === 1 ? dateTime(c.next_run_at) : 'paused'}</span> },
    {
      key: 'enabled', header: 'Enabled', cell: (c) => (
        <Toggle checked={Number(c.enabled) === 1} disabled={!manage || update.isPending} label={<span className="sr-only">Enable {c.name}</span>}
          onChange={(v) => update.mutate({ id: c.id, body: { enabled: v } })} />
      ),
    },
    {
      key: 'run', header: <span className="sr-only">Run</span>, align: 'right', cell: (c) => manage ? (
        <Button size="sm" icon={PlayIcon} loading={running === c.id} disabled={running !== null && running !== c.id} onClick={() => run.mutate(c.id)}>Run now</Button>
      ) : null,
    },
  ];

  return (
    <Card>
      <DataTable
        columns={columns}
        rows={q.data}
        rowKey={(c) => c.id}
        loading={q.isLoading}
        error={q.error}
        onRetry={() => q.refetch()}
        empty={<EmptyState icon={ClockIcon} title="No scheduled jobs" description="The default maintenance jobs are created the first time this list loads." />}
      />
    </Card>
  );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-xs text-slate-500 dark:text-slate-400">{label}</div>
      <div className="mt-0.5 text-slate-900 dark:text-slate-100">{value}</div>
    </div>
  );
}

function pretty(s: string | null): string {
  if (!s) return '—';
  try { return JSON.stringify(JSON.parse(s), null, 2); } catch { return s; }
}
