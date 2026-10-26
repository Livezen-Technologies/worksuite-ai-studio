import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, FormModal, Input, Meter, PageHeader, Select, StatusBadge, Toggle,
  useToast, type Column,
} from '../../components/ui';
import { del, get, post, put } from '../../lib/api';
import { dateTime } from '../../lib/format';
import type { Allocation, TrainingNode } from '../portal/types';

interface User { id: number; name: string; email: string; role: string | null }
interface OverviewData { nodes: TrainingNode[]; allocations: Allocation[]; users: User[] }
type Draft = { id?: number; user_id: string; server_id: string; max_cpu_cores: number; max_gpus: number; max_concurrent_jobs: number; expires_at: string; notes: string };

const EMPTY: Draft = { user_id: '', server_id: '', max_cpu_cores: 4, max_gpus: 0, max_concurrent_jobs: 1, expires_at: '', notes: '' };

export default function ComputeAllocations() {
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['compute', 'overview'], queryFn: () => get<OverviewData>('/compute/overview') });
  const [draft, setDraft] = useState<Draft | null>(null);
  const [nodeEdit, setNodeEdit] = useState<TrainingNode | null>(null);
  const [toDelete, setToDelete] = useState<Allocation | null>(null);
  const [userFilter, setUserFilter] = useState('');
  const refresh = () => qc.invalidateQueries({ queryKey: ['compute'] });

  const save = useMutation({
    mutationFn: (d: Draft) => {
      const body = { max_cpu_cores: d.max_cpu_cores, max_gpus: d.max_gpus, max_concurrent_jobs: d.max_concurrent_jobs, expires_at: d.expires_at || null, notes: d.notes };
      return d.id ? put(`/compute/allocations/${d.id}`, body) : post('/compute/allocations', { ...body, user_id: Number(d.user_id), server_id: Number(d.server_id) });
    },
    onSuccess: () => { toast.success('Allocation saved'); setDraft(null); refresh(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/compute/allocations/${id}`),
    onSuccess: () => { toast.success('Allocation removed'); setToDelete(null); refresh(); },
    onError: (e) => toast.error(e),
  });
  const saveNode = useMutation({
    mutationFn: (n: TrainingNode) => put(`/compute/nodes/${n.id}`, { gpu_count: n.gpu_count, gpu_vram_gb: n.gpu_vram_gb, cpu_cores: n.cpu_cores, allow_training: Number(n.allow_training) === 1 }),
    onSuccess: () => { toast.success('Node updated'); setNodeEdit(null); refresh(); },
    onError: (e) => toast.error(e),
  });

  const nodes = q.data?.nodes ?? [];
  const users = q.data?.users ?? [];
  const allocations = (q.data?.allocations ?? []).filter((a) => !userFilter || String(a.user_id) === userFilter);

  const nodeCols: Column<TrainingNode>[] = [
    { key: 'name', header: 'Node', cell: (n) => <div><div className="font-medium">{n.name}</div><div className="text-xs text-slate-500">{n.gpu_model ?? 'No GPU model recorded'}</div></div> },
    { key: 'st', header: 'Status', cell: (n) => <StatusBadge status={n.status} />, hideBelow: 'sm' },
    { key: 'train', header: 'Training', cell: (n) => Number(n.allow_training) === 1 ? <Badge tone="green">Open</Badge> : <Badge>Closed</Badge> },
    { key: 'cpu', header: 'CPU in use', cell: (n) => <Meter value={n.used_cpu_cores} max={Math.max(1, n.cpu_cores ?? 0)} label={`${n.used_cpu_cores} / ${n.cpu_cores ?? '?'}`} /> },
    { key: 'gpu', header: 'GPUs in use', cell: (n) => <Meter value={n.used_gpus} max={Math.max(1, n.gpu_count)} label={`${n.used_gpus} / ${n.gpu_count}${n.gpu_vram_gb ? ` × ${n.gpu_vram_gb} GB` : ''}`} /> },
    { key: 'jobs', header: 'Jobs', cell: (n) => n.active_jobs, hideBelow: 'md', align: 'right' },
    { key: 'act', header: '', align: 'right', cell: (n) => <Button size="sm" variant="ghost" icon={PencilSquareIcon} onClick={() => setNodeEdit({ ...n })}>Edit</Button> },
  ];
  const allocCols: Column<Allocation>[] = [
    { key: 'user', header: 'User', cell: (a) => <div><div className="font-medium">{a.user_name ?? `User #${a.user_id}`}</div><div className="text-xs text-slate-500">{a.user_email}</div></div> },
    { key: 'node', header: 'Node', cell: (a) => a.server_name },
    { key: 'cpu', header: 'CPU (used / max)', cell: (a) => `${a.used_cpu_cores} / ${a.max_cpu_cores}` },
    { key: 'gpu', header: 'GPU (used / max)', cell: (a) => `${a.used_gpus} / ${a.max_gpus}` },
    { key: 'jobs', header: 'Jobs', cell: (a) => `${a.active_jobs} / ${a.max_concurrent_jobs}`, hideBelow: 'md' },
    { key: 'exp', header: 'Expires', cell: (a) => a.expired ? <Badge tone="red">Expired</Badge> : a.expires_at ? dateTime(a.expires_at) : 'Never', hideBelow: 'lg' },
    { key: 'act', header: '', align: 'right', cell: (a) => (
      <ActionMenu actions={[
        { label: 'Edit', icon: PencilSquareIcon, onClick: () => setDraft({ id: a.id, user_id: String(a.user_id), server_id: String(a.server_id), max_cpu_cores: a.max_cpu_cores, max_gpus: a.max_gpus, max_concurrent_jobs: a.max_concurrent_jobs, expires_at: a.expires_at?.slice(0, 10) ?? '', notes: a.notes ?? '' }) },
        { label: 'Remove', icon: TrashIcon, danger: true, onClick: () => setToDelete(a) },
      ]} />
    ) },
  ];

  const draftNode = draft ? nodes.find((n) => String(n.id) === draft.server_id) : undefined;

  return (
    <div className="space-y-6">
      <PageHeader title="Compute allocations" description="Open nodes for training and give users CPU and GPU capacity on them. Users can only place jobs inside their allocation."
        actions={<Button variant="primary" icon={PlusIcon} onClick={() => setDraft({ ...EMPTY })}>Allocate</Button>} />
      <Card>
        <CardHeader title="Nodes" description="Capacity is what you record here; usage is the sum of pending and running placements." />
        <DataTable columns={nodeCols} rows={nodes} rowKey={(n) => n.id} loading={q.isLoading} error={q.error} onRetry={q.refetch} empty="No nodes registered." />
      </Card>
      <Card>
        <CardHeader title="Allocations" actions={
          <Select aria-label="Filter by user" value={userFilter} onChange={(e) => setUserFilter(e.target.value)} placeholder="All users"
            options={users.map((u) => ({ value: u.id, label: u.name }))} />
        } />
        <DataTable columns={allocCols} rows={allocations} rowKey={(a) => a.id} loading={q.isLoading} empty="No allocations yet." />
      </Card>

      {draft && (
        <FormModal open onClose={() => setDraft(null)} title={draft.id ? 'Edit allocation' : 'New allocation'} onSubmit={() => save.mutate(draft)} submitting={save.isPending}>
          <Select label="User" required disabled={Boolean(draft.id)} value={draft.user_id} onChange={(e) => setDraft({ ...draft, user_id: e.target.value })} placeholder="Choose a user"
            options={users.map((u) => ({ value: u.id, label: `${u.name} (${u.email}${u.role ? `, ${u.role}` : ''})` }))} />
          <Select label="Node" required disabled={Boolean(draft.id)} value={draft.server_id} onChange={(e) => setDraft({ ...draft, server_id: e.target.value })} placeholder="Choose a node"
            options={nodes.map((n) => ({ value: n.id, label: `${n.name}${Number(n.allow_training) === 1 ? '' : ' (training closed)'}` }))} />
          <div className="grid gap-4 sm:grid-cols-3">
            <Input label="Max CPU cores" type="number" min={0} value={draft.max_cpu_cores} onChange={(e) => setDraft({ ...draft, max_cpu_cores: Number(e.target.value) || 0 })}
              hint={draftNode?.cpu_cores ? `Node has ${draftNode.cpu_cores}` : undefined} />
            <Input label="Max GPUs" type="number" min={0} value={draft.max_gpus} onChange={(e) => setDraft({ ...draft, max_gpus: Number(e.target.value) || 0 })}
              hint={draftNode ? `Node has ${draftNode.gpu_count}` : undefined} />
            <Input label="Concurrent jobs" type="number" min={0} value={draft.max_concurrent_jobs} onChange={(e) => setDraft({ ...draft, max_concurrent_jobs: Number(e.target.value) || 0 })} />
          </div>
          <Input label="Expires (optional)" type="date" value={draft.expires_at} onChange={(e) => setDraft({ ...draft, expires_at: e.target.value })} />
          <Input label="Notes" value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} />
        </FormModal>
      )}

      {nodeEdit && (
        <FormModal open onClose={() => setNodeEdit(null)} title={`Node: ${nodeEdit.name}`} onSubmit={() => saveNode.mutate(nodeEdit)} submitting={saveNode.isPending}>
          <Toggle checked={Number(nodeEdit.allow_training) === 1} onChange={(v) => setNodeEdit({ ...nodeEdit, allow_training: v ? 1 : 0 })}
            label="Open for training" description="Users with an allocation can place jobs here." />
          <div className="grid gap-4 sm:grid-cols-3">
            <Input label="CPU cores" type="number" min={0} value={nodeEdit.cpu_cores ?? ''} onChange={(e) => setNodeEdit({ ...nodeEdit, cpu_cores: e.target.value === '' ? null : Number(e.target.value) })} />
            <Input label="GPU count" type="number" min={0} value={nodeEdit.gpu_count} onChange={(e) => setNodeEdit({ ...nodeEdit, gpu_count: Number(e.target.value) || 0 })} />
            <Input label="VRAM per GPU (GB)" type="number" min={0} step="0.1" value={nodeEdit.gpu_vram_gb ?? ''} onChange={(e) => setNodeEdit({ ...nodeEdit, gpu_vram_gb: e.target.value || null })} />
          </div>
        </FormModal>
      )}

      <ConfirmDialog open={toDelete !== null} onClose={() => setToDelete(null)} onConfirm={() => toDelete && remove.mutate(toDelete.id)} busy={remove.isPending}
        title="Remove allocation?" confirmLabel="Remove"
        message={`${toDelete?.user_name} will no longer be able to place new jobs on ${toDelete?.server_name}. Jobs already placed keep running.`} />
    </div>
  );
}
