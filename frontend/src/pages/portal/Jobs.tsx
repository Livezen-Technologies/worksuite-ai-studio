import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArchiveBoxArrowDownIcon, ArrowDownTrayIcon, PlusIcon, ServerStackIcon, XCircleIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, DataTable, FormModal, Input, Meter, Modal, PageHeader, Select, StatusBadge, Textarea,
  useToast, type Column,
} from '../../components/ui';
import { ApiError, download, get, post, put } from '../../lib/api';
import { ago, dateTime } from '../../lib/format';
import NodePicker from './NodePicker';
import { NODE_STATUS_LABEL, PLACEMENT_LABEL, type Allocation, type JobNode, type NodeRequest, type PlacementStatus } from './types';

export interface PortalJob {
  id: number; name: string; base_model: string; method: 'lora' | 'qlora'; dataset_id: number | null; dataset_name: string | null;
  placement_status: PlacementStatus; notes: string | null; created_at: string; nodes: JobNode[]; kit_ready?: boolean;
}
interface JobsData { jobs: PortalJob[]; base_models: string[] }
interface Dataset { id: number; name: string; item_count: number | string; mine: boolean }

/** Per-node status rows; shared with the admin placement screen. */
export function NodeStatusList({ nodes }: { nodes: JobNode[] }) {
  if (!nodes.length) return <p className="text-sm text-slate-500">Not placed on any node.</p>;
  return (
    <ul className="divide-y divide-slate-200 rounded-lg ring-1 ring-slate-200 dark:divide-white/10 dark:ring-white/10">
      {nodes.map((n) => (
        <li key={n.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
          <ServerStackIcon className="size-5 text-slate-400" aria-hidden />
          <div className="min-w-32 flex-1">
            <div className="font-medium">{n.server_name ?? `Node #${n.server_id}`}</div>
            <div className="text-xs text-slate-500">{n.cpu_cores} CPU · {n.gpus} GPU{n.gpu_model ? ` · ${n.gpu_model}` : ''}</div>
          </div>
          {Number(n.override) === 1 && <Badge tone="amber">Admin override</Badge>}
          <StatusBadge status={n.status} label={NODE_STATUS_LABEL[n.status]} />
          <div className="w-32"><Meter value={n.progress} label={`${n.progress}%`} /></div>
          {n.message && <div className="w-full text-xs text-slate-500">{n.message}</div>}
        </li>
      ))}
    </ul>
  );
}

export default function PortalJobs() {
  const toast = useToast();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [creating, setCreating] = useState(params.get('new') === '1');
  const [openId, setOpenId] = useState<number | null>(null);

  useEffect(() => { if (params.get('new') === '1') { setCreating(true); setParams({}, { replace: true }); } }, [params, setParams]);

  const list = useQuery({ queryKey: ['portal', 'jobs'], queryFn: () => get<JobsData>('/portal/jobs') });
  const refresh = () => qc.invalidateQueries({ queryKey: ['portal'] });
  const cancel = useMutation({
    mutationFn: (id: number) => post(`/portal/jobs/${id}/cancel`),
    onSuccess: () => { toast.success('Job cancelled'); refresh(); },
    onError: (e) => toast.error(e),
  });

  const cols: Column<PortalJob>[] = [
    { key: 'name', header: 'Job', cell: (j) => <div><div className="font-medium">{j.name}</div><div className="text-xs text-slate-500">{j.base_model} · {j.method.toUpperCase()}</div></div> },
    { key: 'ds', header: 'Dataset', cell: (j) => j.dataset_name ?? '—', hideBelow: 'md' },
    { key: 'nodes', header: 'Nodes', hideBelow: 'sm', cell: (j) => (
      <div className="flex flex-wrap gap-1">{j.nodes.map((n) => <Badge key={n.id} tone={n.status === 'failed' ? 'red' : n.status === 'running' ? 'blue' : n.status === 'completed' ? 'green' : 'gray'}>{n.server_name}: {NODE_STATUS_LABEL[n.status]}</Badge>)}</div>
    ) },
    { key: 'st', header: 'Status', cell: (j) => <StatusBadge status={j.placement_status} label={PLACEMENT_LABEL[j.placement_status] ?? j.placement_status} /> },
    { key: 'at', header: 'Created', cell: (j) => ago(j.created_at), hideBelow: 'lg' },
    { key: 'act', header: '', align: 'right', cell: (j) => (
      <ActionMenu actions={[
        { label: 'Details', icon: ServerStackIcon, onClick: () => setOpenId(j.id) },
        { label: 'Cancel', icon: XCircleIcon, danger: true, hidden: !['pending', 'running'].includes(j.placement_status), onClick: () => cancel.mutate(j.id) },
      ]} />
    ) },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="My training jobs" description="Fine-tune a base model on your dataset, on the nodes you are allocated."
        actions={<Button variant="primary" icon={PlusIcon} onClick={() => setCreating(true)}>New job</Button>} />
      <Card>
        <DataTable columns={cols} rows={list.data?.jobs} rowKey={(j) => j.id} loading={list.isLoading} error={list.error} onRetry={list.refetch}
          onRowClick={(j) => setOpenId(j.id)} empty="No training jobs yet." />
      </Card>
      <NewJob open={creating} onClose={() => setCreating(false)} baseModels={list.data?.base_models ?? []} onCreated={(id) => { refresh(); setOpenId(id); }} />
      {openId !== null && <JobDetail id={openId} onClose={() => setOpenId(null)} />}
    </div>
  );
}

function NewJob({ open, onClose, baseModels, onCreated }: { open: boolean; onClose: () => void; baseModels: string[]; onCreated: (id: number) => void }) {
  const toast = useToast();
  const [name, setName] = useState('');
  const [base, setBase] = useState('');
  const [method, setMethod] = useState<'lora' | 'qlora'>('qlora');
  const [datasetId, setDatasetId] = useState('');
  const [notes, setNotes] = useState('');
  const [nodes, setNodes] = useState<NodeRequest[]>([]);
  const [problems, setProblems] = useState<string[]>([]);

  const datasets = useQuery({ queryKey: ['portal', 'datasets'], queryFn: () => get<Dataset[]>('/portal/datasets'), enabled: open });
  const allocs = useQuery({ queryKey: ['portal', 'nodes'], queryFn: () => get<Allocation[]>('/portal/nodes'), enabled: open });
  useEffect(() => { if (!base && baseModels[0]) setBase(baseModels[0]); }, [base, baseModels]);

  const create = useMutation({
    mutationFn: () => post<PortalJob>('/portal/jobs', { name, base_model: base, method, dataset_id: datasetId ? Number(datasetId) : null, notes, nodes }),
    onSuccess: (job) => {
      toast.success('Training job created');
      setName(''); setNotes(''); setNodes([]); setProblems([]);
      onClose(); onCreated(job.id);
    },
    onError: (e) => {
      const p = e instanceof ApiError && e.data && typeof e.data === 'object' ? (e.data as { problems?: string[] }).problems : undefined;
      if (p?.length) setProblems(p); else toast.error(e);
    },
  });

  return (
    <FormModal open={open} onClose={onClose} title="New training job" size="lg" onSubmit={() => create.mutate()} submitLabel="Create job" submitting={create.isPending}>
      <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} autoFocus />
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label="Base model" value={base} onChange={(e) => setBase(e.target.value)} options={baseModels.map((m) => ({ value: m, label: m }))} />
        <Select label="Method" value={method} onChange={(e) => setMethod(e.target.value as 'lora' | 'qlora')}
          options={[{ value: 'qlora', label: 'QLoRA (4-bit, less VRAM)' }, { value: 'lora', label: 'LoRA' }]} />
      </div>
      <Select label="Dataset" value={datasetId} onChange={(e) => setDatasetId(e.target.value)} placeholder="Choose a dataset"
        options={(datasets.data ?? []).map((d) => ({ value: d.id, label: `${d.name} (${d.item_count} rows)${d.mine ? '' : ' — shared'}` }))} />
      <div>
        <div className="mb-2 text-sm font-medium">Nodes</div>
        <NodePicker allocations={allocs.data ?? []} value={nodes} onChange={(v) => { setNodes(v); setProblems([]); }} />
      </div>
      {problems.length > 0 && (
        <Alert tone="danger" title="Outside your allocation">
          <ul className="list-disc pl-5">{problems.map((p) => <li key={p}>{p}</li>)}</ul>
        </Alert>
      )}
      <Textarea label="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
    </FormModal>
  );
}

function JobDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const job = useQuery({ queryKey: ['portal', 'job', id], queryFn: () => get<PortalJob>(`/portal/jobs/${id}`), refetchInterval: 15000 });
  const allocs = useQuery({ queryKey: ['portal', 'nodes'], queryFn: () => get<Allocation[]>('/portal/nodes') });
  const [editing, setEditing] = useState<NodeRequest[] | null>(null);
  const [kitError, setKitError] = useState<string | null>(null);
  const j = job.data;
  const refresh = () => qc.invalidateQueries({ queryKey: ['portal'] });

  const kit = useMutation({
    mutationFn: () => post<PortalJob>(`/portal/jobs/${id}/kit`),
    onSuccess: () => { setKitError(null); toast.success('Training kit generated'); refresh(); },
    onError: (e) => setKitError(e instanceof Error ? e.message : String(e)),
  });
  const replace = useMutation({
    mutationFn: (nodes: NodeRequest[]) => put<PortalJob>(`/portal/jobs/${id}/nodes`, { nodes }),
    onSuccess: () => { setEditing(null); toast.success('Placement updated'); refresh(); },
    onError: (e) => toast.error(e),
  });

  const held: Record<number, { cpu: number; gpus: number }> = {};
  for (const n of j?.nodes ?? []) if (['pending', 'assigned', 'running'].includes(n.status)) held[n.server_id] = { cpu: n.cpu_cores, gpus: n.gpus };
  const canReplace = j && ['unplaced', 'pending', 'cancelled', 'failed'].includes(j.placement_status);

  return (
    <Modal open onClose={onClose} size="lg" title={j?.name ?? 'Training job'}
      description={j ? `${j.base_model} · ${j.method.toUpperCase()} · created ${dateTime(j.created_at)}` : undefined}
      footer={j && (
        <div className="flex flex-wrap justify-end gap-2">
          <Button icon={ArchiveBoxArrowDownIcon} loading={kit.isPending} onClick={() => kit.mutate()}>Generate kit</Button>
          {j.kit_ready && <Button icon={ArrowDownTrayIcon} onClick={() => download(`/portal/jobs/${id}/kit`, `sinlama-training-kit-${id}.zip`).catch(toast.error)}>Download kit</Button>}
          <Button variant="primary" onClick={onClose}>Close</Button>
        </div>
      )}>
      {j && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <StatusBadge status={j.placement_status} label={PLACEMENT_LABEL[j.placement_status] ?? j.placement_status} />
            <span className="text-slate-500">Dataset: {j.dataset_name ?? 'none'}</span>
          </div>
          {kitError && <Alert tone="danger" title="Kit not generated">{kitError}</Alert>}
          <div>
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-sm font-medium">Placement</h3>
              {canReplace && !editing && <Button size="sm" onClick={() => setEditing(j.nodes.map((n) => ({ server_id: n.server_id, cpu_cores: n.cpu_cores, gpus: n.gpus })))}>Change nodes</Button>}
            </div>
            {editing ? (
              <div className="space-y-3">
                <NodePicker allocations={allocs.data ?? []} value={editing} onChange={setEditing} extraFree={held} />
                <div className="flex justify-end gap-2">
                  <Button onClick={() => setEditing(null)}>Cancel</Button>
                  <Button variant="primary" loading={replace.isPending} onClick={() => replace.mutate(editing)}>Save placement</Button>
                </div>
              </div>
            ) : <NodeStatusList nodes={j.nodes} />}
          </div>
          {j.notes && <p className="text-sm text-slate-600 dark:text-slate-300">{j.notes}</p>}
        </div>
      )}
    </Modal>
  );
}
