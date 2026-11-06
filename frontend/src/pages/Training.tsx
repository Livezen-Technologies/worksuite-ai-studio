import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AcademicCapIcon, ArchiveBoxArrowDownIcon, ArrowPathIcon, StopCircleIcon, BeakerIcon, ChartBarIcon, ArrowDownTrayIcon, PencilSquareIcon, PlusIcon, TrashIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState,
  PageHeader, SearchInput, StatusBadge, Tabs, Toolbar, useDisclosure, useToast, type Column,
} from '../components/ui';
import { ApiError, del, download, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, dateTime, num } from '../lib/format';
import TrainingLineage from './SinLama/TrainingLineage';
import TrainingLive, { JobProgress } from './Training/TrainingLive';
import TrainingPipelines from './Training/Pipelines';
import MlModels from './Training/MlModels';
import TableModels from './Training/TableModels';
import JobForm from './Training/JobForm';
import { ACTIVE, HAS_KIT, parseHyper, STATUSES, statusLabel, type Job, type ListResponse, type Status } from './Training/shared';

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
