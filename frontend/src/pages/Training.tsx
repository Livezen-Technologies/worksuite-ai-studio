import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AcademicCapIcon, ArchiveBoxArrowDownIcon, ArrowPathIcon, StopCircleIcon, BeakerIcon, ChartBarIcon, ArrowDownTrayIcon, CpuChipIcon, PencilSquareIcon, PlusIcon, TrashIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState, FormModal, FormSection, Input,
  PageHeader, SearchInput, Select, StatusBadge, Tabs, Textarea, Toolbar, useDisclosure, useToast, type Column,
} from '../components/ui';
import { ApiError, del, download, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, dateTime, num } from '../lib/format';
import TrainingLineage, { type LineageJob } from './SinLama/TrainingLineage';
import TrainingLive, { JobProgress } from './Training/TrainingLive';
import TrainingPipelines from './Training/Pipelines';
import MlModels from './Training/MlModels';
import TableModels from './Training/TableModels';

type Status = 'draft' | 'kit_ready' | 'preparing' | 'queued' | 'running' | 'completed' | 'failed' | 'cancelled' | 'imported';
interface Hyper {
  epochs: number; learning_rate: number; lora_rank: number; lora_alpha: number; lora_dropout: number;
  batch_size: number; grad_accum: number; max_seq_len: number; warmup_steps: number; seed: number; quant_export: string;
}
interface Job extends LineageJob {
  id: number; name: string; dataset_id: number | null; dataset_name: string | null; dataset_items: number | null;
  base_model: string; method: 'lora' | 'qlora' | 'finetune'; hyperparams: string | Partial<Hyper> | null; status: Status;
  notes: string | null; logs: string | null; created_at: string; updated_at: string | null;
  progress_pct?: string | number | null; eta_seconds?: number | string | null; current_step?: number | string | null;
  total_steps?: number | string | null; phase?: string | null; last_report_at?: string | null;
  /** set for jobs a training pipeline created (they run through a node's training agent) */
  pipeline_id?: number | null; recipe?: string | null; error?: string | null; trigger_reason?: string | null; attempt?: number | string | null;
}
const ACTIVE: Status[] = ['preparing', 'queued', 'running'];
interface ListResponse { jobs: Job[]; base_models: string[]; defaults: Hyper }
interface Dataset { id: number; name: string; item_count: number | string }

const STATUSES: { value: Status; label: string }[] = [
  { value: 'draft', label: 'Draft' },
  { value: 'kit_ready', label: 'Kit ready' },
  { value: 'preparing', label: 'Preparing' },
  { value: 'queued', label: 'Queued' },
  { value: 'running', label: 'Running' },
  { value: 'completed', label: 'Completed' },
  { value: 'failed', label: 'Failed' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'imported', label: 'Imported' },
];
const statusLabel = (s: Status) => STATUSES.find((x) => x.value === s)?.label ?? s;
const QUANTS = ['q4_k_m', 'q5_k_m', 'q8_0', 'f16'];
const HAS_KIT: Status[] = ['kit_ready', 'running', 'completed', 'failed', 'imported'];

function parseHyper(h: Job['hyperparams']): Partial<Hyper> {
  if (!h) return {};
  if (typeof h === 'object') return h;
  try { return JSON.parse(h) ?? {}; } catch { return {}; }
}

export default function Training() {
  const { can } = useAuth();
  const canManage = can('models.manage');
  const toast = useToast();
  const qc = useQueryClient();
  const [tab, setTab] = useState<'all' | Status>('all');
  const [q, setQ] = useState('');
  const form = useDisclosure();
  const [editing, setEditing] = useState<Job | null>(null);
  const [toDelete, setToDelete] = useState<Job | null>(null);
  const [kitJob, setKitJob] = useState<Job | null>(null);
  const [lineageJob, setLineageJob] = useState<Job | null>(null);
  /** The server's kit refusal (e.g. dataset not frozen / withdrawn), shown verbatim. */
  const [kitRefusal, setKitRefusal] = useState<{ job: Job; message: string } | null>(null);

  const [liveJob, setLiveJob] = useState<number | null>(null);
  // Refresh while anything is running so progress moves without a reload.
  const list = useQuery({
    queryKey: ['training'], queryFn: () => get<ListResponse>('/training'),
    refetchInterval: (query) => (query.state.data?.jobs.some((j) => ACTIVE.includes(j.status)) ? 10_000 : false),
  });
  const jobs = list.data?.jobs ?? [];
  const rows = jobs.filter((j) => (tab === 'all' || j.status === tab)
    && (!q || `${j.name} ${j.base_model} ${j.dataset_name ?? ''}`.toLowerCase().includes(q.toLowerCase())));

  const refresh = () => qc.invalidateQueries({ queryKey: ['training'] });
  const jobAction = useMutation({
    mutationFn: ({ id, action }: { id: number; action: 'cancel' | 'retry' }) => post(`/training/${id}/${action}`),
    onSuccess: (_r, v) => { toast.success(v.action === 'cancel' ? 'Job cancelled' : 'Job queued again'); refresh(); qc.invalidateQueries({ queryKey: ['training', 'pipelines'] }); },
    onError: (e) => toast.error(e),
  });
  const kit = useMutation({
    mutationFn: (id: number) => post<Job>(`/training/${id}/kit`),
    onSuccess: (_, id) => { setKitRefusal((r) => (r?.job.id === id ? null : r)); toast.success('Training kit generated', 'Download it and run it on a GPU.'); refresh(); },
    onError: (e, id) => { const j = jobs.find((x) => x.id === id); if (j) setKitRefusal({ job: j, message: e instanceof Error ? e.message : String(e) }); toast.error(e); },
    onSettled: () => setKitJob(null),
  });
  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: Status }) => put<Job>(`/training/${id}`, { status }),
    onSuccess: (_, v) => { toast.success(`Status set to ${statusLabel(v.status)}`); refresh(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/training/${id}`),
    onSuccess: () => { toast.success('Job deleted'); setToDelete(null); refresh(); },
    onError: (e) => toast.error(e),
  });

  async function downloadKit(j: Job) {
    try { await download(`/training/${j.id}/kit`, `sinlama-training-kit-${j.id}.zip`); toast.success('Kit download started'); }
    catch (e) { toast.error(e instanceof ApiError && e.status === 404 ? 'Kit not generated yet — generate it first.' : e); }
  }

  const counts = useMemo(() => {
    const c: Record<string, number> = {};
    for (const j of jobs) c[j.status] = (c[j.status] ?? 0) + 1;
    return c;
  }, [jobs]);

  const columns: Column<Job>[] = [
    {
      key: 'name', header: 'Job', cell: (j) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-slate-900 dark:text-white">{j.name}</p>
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
            {j.base_model}{j.recipe && <> · <Badge tone="blue">pipeline</Badge>{Number(j.attempt) > 1 ? ` attempt ${j.attempt}` : ''}</>}
          </p>
          {j.error && (j.status === 'failed' || j.status === 'queued') && (
            <p className="mt-0.5 line-clamp-2 text-xs text-red-700 dark:text-red-400" title={j.error}>{j.error}</p>
          )}
        </div>
      ),
    },
    {
      key: 'dataset', header: 'Dataset', hideBelow: 'md', cell: (j) => j.dataset_name
        ? <span>{j.dataset_name} <span className="text-slate-500 dark:text-slate-400">({num(j.dataset_items)} rows)</span></span>
        : <span className="text-amber-700 dark:text-amber-400">None</span>,
    },
    {
      key: 'method', header: 'Method', hideBelow: 'lg', cell: (j) => {
        const h = parseHyper(j.hyperparams);
        return (
          <div>
            <Badge tone="purple">{j.method === 'lora' ? 'LoRA' : j.method === 'finetune' ? 'Fine-tune' : 'QLoRA'}</Badge>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              {h.epochs ?? list.data?.defaults.epochs} ep · r{h.lora_rank ?? list.data?.defaults.lora_rank} · {h.quant_export ?? list.data?.defaults.quant_export}
            </p>
          </div>
        );
      },
    },
    { key: 'status', header: 'Status', cell: (j) => <StatusBadge status={j.status === 'kit_ready' || j.status === 'imported' ? 'ready' : j.status} label={statusLabel(j.status)} /> },
    { key: 'progress', header: 'Progress', hideBelow: 'sm', cell: (j) => <JobProgress job={j} /> },
    { key: 'updated', header: 'Updated', hideBelow: 'sm', cell: (j) => <span title={dateTime(j.updated_at ?? j.created_at)}>{ago(j.updated_at ?? j.created_at)}</span> },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (j) => (
        <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
          {canManage && j.recipe && ACTIVE.includes(j.status) && (
            <Button size="sm" variant="secondary" icon={StopCircleIcon} loading={jobAction.isPending} onClick={() => jobAction.mutate({ id: j.id, action: 'cancel' })}>Cancel</Button>
          )}
          {canManage && j.recipe && (j.status === 'failed' || j.status === 'cancelled') && (
            <Button size="sm" variant="secondary" icon={ArrowPathIcon} loading={jobAction.isPending} onClick={() => jobAction.mutate({ id: j.id, action: 'retry' })}>Retry</Button>
          )}
          {canManage && !j.recipe && (
            <Button
              size="sm"
              variant={j.status === 'draft' ? 'primary' : 'secondary'}
              icon={ArchiveBoxArrowDownIcon}
              loading={kit.isPending && kitJob?.id === j.id}
              disabled={!j.dataset_id}
              title={j.dataset_id ? undefined : 'Pick a dataset first'}
              onClick={() => { setKitJob(j); kit.mutate(j.id); }}
            >
              {HAS_KIT.includes(j.status) ? 'Regenerate' : 'Generate kit'}
            </Button>
          )}
          <ActionMenu actions={[
            { label: 'Live progress & logs', icon: ChartBarIcon, onClick: () => setLiveJob(j.id) },
            { label: 'Download kit', icon: ArrowDownTrayIcon, hidden: !!j.recipe, disabled: !HAS_KIT.includes(j.status), onClick: () => downloadKit(j) },
            { label: 'Lineage', icon: BeakerIcon, onClick: () => setLineageJob(j) },
            // Pipeline jobs are run by a node agent: their settings come from the pipeline, and status from the run.
            { label: 'Edit', icon: PencilSquareIcon, hidden: !canManage || !!j.recipe, onClick: () => { setEditing(j); form.onOpen(); } },
            ...STATUSES.filter((s) => s.value !== j.status).map((s) => ({
              label: `Mark ${s.label.toLowerCase()}`, hidden: !canManage || !!j.recipe, onClick: () => setStatus.mutate({ id: j.id, status: s.value }),
            })),
            { label: 'Delete', icon: TrashIcon, danger: true, hidden: !canManage, onClick: () => setToDelete(j) },
          ]} />
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Training"
        description="LoRA / QLoRA fine-tuning jobs for SinLama. Generate a kit, train on a GPU, import the GGUF in Model Studio."
        actions={canManage && <Button icon={PlusIcon} onClick={() => { setEditing(null); form.onOpen(); }}>New training job</Button>}
      />

      <TrainingPipelines canManage={canManage} />
      <MlModels canManage={canManage} />
      <TableModels canManage={canManage} />

      {kitRefusal && (
        <Alert tone="danger" title={`Kit not generated for “${kitRefusal.job.name}”`} className="mb-6">{kitRefusal.message}</Alert>
      )}

      <Card>
        <Tabs
          className="px-4 sm:px-6"
          value={tab}
          onChange={(k) => setTab(k as typeof tab)}
          tabs={[{ key: 'all', label: 'All', count: jobs.length }, ...STATUSES.map((s) => ({ key: s.value, label: s.label, count: counts[s.value] ?? 0 }))]}
        />
        <Toolbar><SearchInput value={q} onChange={setQ} placeholder="Search jobs, models, datasets…" className="w-full sm:max-w-xs" /></Toolbar>
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(j) => j.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => list.refetch()}
          onRowClick={(j) => setLiveJob(j.id)}
          empty={
            <EmptyState
              icon={AcademicCapIcon}
              title={jobs.length ? 'No jobs match' : 'No training jobs yet'}
              description={jobs.length ? 'Try another status or search.' : 'Create a job from a dataset, then generate its training kit.'}
              action={!jobs.length && canManage ? <Button icon={PlusIcon} onClick={() => { setEditing(null); form.onOpen(); }}>New training job</Button> : undefined}
            />
          }
        />
      </Card>

      {list.data && (
        <JobForm
          key={editing?.id ?? 'new'}
          open={form.open}
          onClose={form.onClose}
          job={editing}
          baseModels={list.data.base_models}
          defaults={list.data.defaults}
        />
      )}
      {liveJob !== null && <TrainingLive id={liveJob} onClose={() => setLiveJob(null)} />}
      <TrainingLineage
        open={lineageJob !== null}
        onClose={() => setLineageJob(null)}
        job={lineageJob && (jobs.find((x) => x.id === lineageJob.id) ?? lineageJob)}
        kitRefusal={kitRefusal && lineageJob && kitRefusal.job.id === lineageJob.id ? kitRefusal.message : null}
      />
      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id)}
        busy={remove.isPending}
        title="Delete this job?"
        message={<>“{toDelete?.name}” and its generated kit record will be removed. The dataset is kept.</>}
      />
    </>
  );
}

const HYPER_FIELDS: { key: keyof Hyper; label: string; step?: number; min?: number; hint?: string }[] = [
  { key: 'epochs', label: 'Epochs', min: 1, step: 1 },
  { key: 'learning_rate', label: 'Learning rate', min: 0, step: 0.00001, hint: 'e.g. 0.0002' },
  { key: 'lora_rank', label: 'LoRA rank (r)', min: 1, step: 1 },
  { key: 'lora_alpha', label: 'LoRA alpha', min: 1, step: 1 },
  { key: 'lora_dropout', label: 'LoRA dropout', min: 0, step: 0.01 },
  { key: 'batch_size', label: 'Batch size', min: 1, step: 1 },
  { key: 'grad_accum', label: 'Gradient accumulation', min: 1, step: 1 },
  { key: 'max_seq_len', label: 'Max sequence length', min: 128, step: 128 },
  { key: 'warmup_steps', label: 'Warmup steps', min: 0, step: 1 },
  { key: 'seed', label: 'Seed', min: 0, step: 1 },
];

function JobForm({ open, onClose, job, baseModels, defaults }: {
  open: boolean; onClose: () => void; job: Job | null; baseModels: string[]; defaults: Hyper;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const datasets = useQuery({ queryKey: ['datasets'], queryFn: () => get<Dataset[]>('/datasets'), enabled: open });
  const init = { ...defaults, ...parseHyper(job?.hyperparams ?? null) };
  const [name, setName] = useState(job?.name ?? '');
  const [datasetId, setDatasetId] = useState(job?.dataset_id ? String(job.dataset_id) : '');
  const [base, setBase] = useState(job?.base_model ?? baseModels[0] ?? '');
  const [method, setMethod] = useState<'lora' | 'qlora'>(job?.method === 'lora' ? 'lora' : 'qlora');
  const [status, setStatus] = useState<Status>(job?.status ?? 'draft');
  const [notes, setNotes] = useState(job?.notes ?? '');
  const [logs, setLogs] = useState(job?.logs ?? '');
  const [hp, setHp] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(init).map(([k, v]) => [k, String(v)])));
  const [errors, setErrors] = useState<Record<string, string>>({});

  const save = useMutation({
    mutationFn: () => {
      const hyperparams: Record<string, string | number> = {};
      for (const f of HYPER_FIELDS) if (hp[f.key] !== '') hyperparams[f.key] = Number(hp[f.key]);
      hyperparams.quant_export = hp.quant_export || defaults.quant_export;
      const body = {
        name: name.trim(), dataset_id: datasetId ? Number(datasetId) : 0, base_model: base.trim(), method, hyperparams, notes: notes.trim() || null,
        ...(job ? { status, logs: logs || null } : {}),
      };
      return job ? put<Job>(`/training/${job.id}`, body) : post<Job>('/training', body);
    },
    onSuccess: () => {
      toast.success(job ? 'Job updated' : 'Training job created');
      qc.invalidateQueries({ queryKey: ['training'] });
      onClose();
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });

  function submit() {
    const e: Record<string, string> = {};
    if (!name.trim()) e.name = 'Name the job.';
    if (!base.trim()) e.base_model = 'Pick a base model.';
    for (const f of HYPER_FIELDS) if (hp[f.key] !== '' && Number.isNaN(Number(hp[f.key]))) e[f.key] = 'Must be a number.';
    setErrors(e);
    if (!Object.keys(e).length) save.mutate();
  }

  const quantOptions = Array.from(new Set([...QUANTS, String(hp.quant_export || defaults.quant_export)])).map((x) => ({ value: x, label: x }));

  return (
    <FormModal
      open={open}
      onClose={onClose}
      size="xl"
      title={job ? `Edit ${job.name}` : 'New training job'}
      description="Hyperparameters go into the generated train.py. Defaults suit a 3B–8B model on a single 24 GB GPU."
      submitLabel={job ? 'Save changes' : 'Create job'}
      submitting={save.isPending}
      onSubmit={submit}
    >
      <div className="space-y-8">
        <FormSection title="Job">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="sinlama-support-qwen3b" error={errors.name} wrapperClassName="sm:col-span-2" />
            <Select
              label="Dataset"
              value={datasetId}
              onChange={(e) => setDatasetId(e.target.value)}
              options={(datasets.data ?? []).map((d) => ({ value: d.id, label: `${d.name} (${num(d.item_count)} rows)` }))}
              placeholder={datasets.isLoading ? 'Loading…' : 'No dataset yet'}
              hint="Needed before a kit can be generated."
              error={errors.dataset_id}
            />
            <Select label="Method" value={method} onChange={(e) => setMethod(e.target.value as 'lora' | 'qlora')} options={[{ value: 'qlora', label: 'QLoRA (4-bit, less VRAM)' }, { value: 'lora', label: 'LoRA (16-bit)' }]} />
            <div className="sm:col-span-2">
              <Input
                label="Base model"
                required
                list="training-base-models"
                value={base}
                onChange={(e) => setBase(e.target.value)}
                placeholder="unsloth/Qwen2.5-3B-Instruct"
                hint="A Hugging Face model id; Unsloth-optimised suggestions are listed."
                error={errors.base_model}
              />
              <datalist id="training-base-models">{baseModels.map((m) => <option key={m} value={m} />)}</datalist>
            </div>
            {job && <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value as Status)} options={STATUSES} />}
          </div>
        </FormSection>

        <FormSection title="Hyperparameters">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            {HYPER_FIELDS.map((f) => (
              <Input
                key={f.key}
                label={f.label}
                type="number"
                min={f.min}
                step={f.step}
                value={hp[f.key] ?? ''}
                onChange={(e) => setHp((h) => ({ ...h, [f.key]: e.target.value }))}
                placeholder={String(defaults[f.key])}
                error={errors[f.key]}
              />
            ))}
            <Select label="GGUF export quant" value={hp.quant_export ?? ''} onChange={(e) => setHp((h) => ({ ...h, quant_export: e.target.value }))} options={quantOptions} />
          </div>
          <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">
            Effective batch size: <span className="font-medium tabular-nums text-slate-700 dark:text-slate-300">{(Number(hp.batch_size) || 0) * (Number(hp.grad_accum) || 0)}</span>
          </p>
        </FormSection>

        <FormSection title="Notes">
          <div className="space-y-4">
            <Textarea label="Notes" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="GPU used, cost, eval results…" />
            {job && <Textarea label="Training log" rows={5} className="font-mono text-xs" value={logs} onChange={(e) => setLogs(e.target.value)} placeholder="Paste the trainer output here to keep it with the job." />}
          </div>
        </FormSection>

        {!datasetId && (
          <Alert tone="warning"><CpuChipIcon className="mr-1 inline size-4" aria-hidden />Without a dataset the job can be saved, but no kit can be generated.</Alert>
        )}
      </div>
    </FormModal>
  );
}
