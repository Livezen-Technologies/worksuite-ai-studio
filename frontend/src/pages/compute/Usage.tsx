import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowsRightLeftIcon } from '@heroicons/react/24/outline';
import {
  Alert, Badge, Button, Card, CardHeader, Checkbox, DataTable, Input, Modal, PageHeader, Select, StatusBadge, Tabs, useToast, type Column,
} from '../../components/ui';
import { get, put } from '../../lib/api';
import { ago } from '../../lib/format';
import { NodeStatusList } from '../portal/Jobs';
import { NODE_STATUS_LABEL, PLACEMENT_LABEL, type Allocation, type JobNode, type NodeRequest, type NodeStatus, type PlacementStatus, type TrainingNode } from '../portal/types';

interface AdminJob {
  id: number; name: string; base_model: string; placement_status: PlacementStatus; created_at: string;
  created_by: number | null; user_name: string | null; dataset_name: string | null; nodes: JobNode[];
}
interface UsageData { allocations: Allocation[]; nodes: TrainingNode[]; placements: (JobNode & { job_name: string; user_name: string | null })[] }
interface UserRow { user_id: number; user_name: string; cpu: number; maxCpu: number; gpus: number; maxGpus: number; jobs: number; nodes: string[] }

export default function ComputeUsage() {
  const [tab, setTab] = useState('jobs');
  const usage = useQuery({ queryKey: ['compute', 'usage'], queryFn: () => get<UsageData>('/compute/usage') });
  const jobs = useQuery({ queryKey: ['compute', 'jobs'], queryFn: () => get<AdminJob[]>('/compute/jobs'), refetchInterval: 20000 });
  const [placing, setPlacing] = useState<AdminJob | null>(null);

  const byUser = new Map<number, UserRow>();
  for (const a of usage.data?.allocations ?? []) {
    const r = byUser.get(a.user_id) ?? { user_id: a.user_id, user_name: a.user_name ?? `User #${a.user_id}`, cpu: 0, maxCpu: 0, gpus: 0, maxGpus: 0, jobs: 0, nodes: [] };
    r.cpu += a.used_cpu_cores; r.maxCpu += a.max_cpu_cores; r.gpus += a.used_gpus; r.maxGpus += a.max_gpus; r.jobs += a.active_jobs; r.nodes.push(a.server_name);
    byUser.set(a.user_id, r);
  }

  const userCols: Column<UserRow>[] = [
    { key: 'u', header: 'User', cell: (r) => <span className="font-medium">{r.user_name}</span> },
    { key: 'n', header: 'Nodes', cell: (r) => r.nodes.join(', '), hideBelow: 'md' },
    { key: 'c', header: 'CPU (used / allocated)', cell: (r) => `${r.cpu} / ${r.maxCpu}` },
    { key: 'g', header: 'GPU (used / allocated)', cell: (r) => `${r.gpus} / ${r.maxGpus}` },
    { key: 'j', header: 'Active jobs', cell: (r) => r.jobs, align: 'right' },
  ];
  const jobCols: Column<AdminJob>[] = [
    { key: 'name', header: 'Job', cell: (j) => <div><div className="font-medium">{j.name}</div><div className="text-xs text-slate-500">{j.base_model}</div></div> },
    { key: 'user', header: 'Owner', cell: (j) => j.user_name ?? '—', hideBelow: 'sm' },
    { key: 'nodes', header: 'Placement', cell: (j) => (
      <div className="flex flex-wrap gap-1">
        {j.nodes.length ? j.nodes.map((n) => <Badge key={n.id} tone={Number(n.override) === 1 ? 'amber' : 'gray'}>{n.server_name} · {n.cpu_cores}C/{n.gpus}G · {NODE_STATUS_LABEL[n.status]}</Badge>) : '—'}
      </div>
    ) },
    { key: 'st', header: 'Status', cell: (j) => <StatusBadge status={j.placement_status} label={PLACEMENT_LABEL[j.placement_status] ?? j.placement_status} /> },
    { key: 'at', header: 'Created', cell: (j) => ago(j.created_at), hideBelow: 'lg' },
    { key: 'act', header: '', align: 'right', cell: (j) => <Button size="sm" variant="ghost" icon={ArrowsRightLeftIcon} onClick={() => setPlacing(j)}>Placement</Button> },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="Compute usage" description="Who is using which node, and where each training job runs. Change or override placement here." />
      <Tabs value={tab} onChange={setTab} tabs={[{ key: 'jobs', label: 'Jobs', count: jobs.data?.length }, { key: 'users', label: 'By user', count: byUser.size }]} />
      {tab === 'jobs' ? (
        <Card>
          <DataTable columns={jobCols} rows={jobs.data} rowKey={(j) => j.id} loading={jobs.isLoading} error={jobs.error} onRetry={jobs.refetch}
            onRowClick={setPlacing} empty="No training jobs yet." />
        </Card>
      ) : (
        <Card>
          <CardHeader title="Usage by user" description="Totals across every node the user is allocated." />
          <DataTable columns={userCols} rows={[...byUser.values()]} rowKey={(r) => r.user_id} loading={usage.isLoading} error={usage.error} empty="No allocations yet." />
        </Card>
      )}
      {placing && <PlacementDialog job={placing} nodes={usage.data?.nodes ?? []} onClose={() => setPlacing(null)} />}
    </div>
  );
}

function PlacementDialog({ job, nodes, onClose }: { job: AdminJob; nodes: TrainingNode[]; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [rows, setRows] = useState<NodeRequest[]>(job.nodes.map((n) => ({ server_id: n.server_id, cpu_cores: n.cpu_cores, gpus: n.gpus })));
  const [override, setOverride] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [current, setCurrent] = useState<JobNode[]>(job.nodes);
  const refresh = () => qc.invalidateQueries({ queryKey: ['compute'] });

  const save = useMutation({
    mutationFn: () => put<JobNode[]>(`/compute/jobs/${job.id}/placement`, { nodes: rows, override }),
    onSuccess: (n) => { setCurrent(n); setError(null); toast.success('Placement saved'); refresh(); },
    onError: (e) => setError(e instanceof Error ? e.message : String(e)),
  });
  const setStatus = useMutation({
    mutationFn: ({ sid, status }: { sid: number; status: NodeStatus }) => put<JobNode[]>(`/compute/jobs/${job.id}/nodes/${sid}`, { status }),
    onSuccess: (n) => { setCurrent(n); refresh(); },
    onError: (e) => toast.error(e),
  });

  const unused = nodes.filter((n) => !rows.some((r) => r.server_id === n.id));
  const upd = (sid: number, patch: Partial<NodeRequest>) => setRows(rows.map((r) => (r.server_id === sid ? { ...r, ...patch } : r)));

  return (
    <Modal open onClose={onClose} size="lg" title={`Placement: ${job.name}`} description={`Owner: ${job.user_name ?? 'unknown'}`}
      footer={<div className="flex justify-end gap-2"><Button onClick={onClose}>Close</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Save placement</Button></div>}>
      <div className="space-y-5">
        <section>
          <h3 className="mb-2 text-sm font-medium">Status per node</h3>
          <NodeStatusList nodes={current} />
          {current.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-2">
              {current.map((n) => (
                <Select key={n.id} aria-label={`Set status on ${n.server_name}`} value={n.status}
                  onChange={(e) => setStatus.mutate({ sid: n.server_id, status: e.target.value as NodeStatus })}
                  options={(Object.keys(NODE_STATUS_LABEL) as NodeStatus[]).map((s) => ({ value: s, label: `${n.server_name}: ${NODE_STATUS_LABEL[s]}` }))} />
              ))}
            </div>
          )}
        </section>
        <section>
          <h3 className="mb-2 text-sm font-medium">Assign nodes</h3>
          <ul className="space-y-2">
            {rows.map((r) => {
              const n = nodes.find((x) => x.id === r.server_id);
              return (
                <li key={r.server_id} className="flex flex-wrap items-end gap-3 rounded-lg p-3 ring-1 ring-slate-200 dark:ring-white/10">
                  <div className="min-w-32 flex-1 text-sm"><div className="font-medium">{n?.name ?? `#${r.server_id}`}</div>
                    <div className="text-xs text-slate-500">{n ? `${n.used_cpu_cores}/${n.cpu_cores ?? '?'} CPU · ${n.used_gpus}/${n.gpu_count} GPU in use` : ''}</div></div>
                  <Input label="CPU" type="number" min={0} className="w-24" value={r.cpu_cores} onChange={(e) => upd(r.server_id, { cpu_cores: Number(e.target.value) || 0 })} />
                  <Input label="GPUs" type="number" min={0} className="w-24" value={r.gpus} onChange={(e) => upd(r.server_id, { gpus: Number(e.target.value) || 0 })} />
                  <Button variant="ghost" onClick={() => setRows(rows.filter((x) => x.server_id !== r.server_id))}>Remove</Button>
                </li>
              );
            })}
          </ul>
          {unused.length > 0 && (
            <Select className="mt-2" aria-label="Add node" value="" placeholder="Add a node…"
              onChange={(e) => e.target.value && setRows([...rows, { server_id: Number(e.target.value), cpu_cores: 1, gpus: 0 }])}
              options={unused.map((n) => ({ value: n.id, label: `${n.name}${Number(n.allow_training) === 1 ? '' : ' (training closed)'}` }))} />
          )}
          <Checkbox className="mt-3" checked={override} onChange={setOverride} label="Override — place even if it exceeds the owner's allocation" />
          {error && <Alert tone="danger" className="mt-3" title="Placement refused">{error}</Alert>}
        </section>
      </div>
    </Modal>
  );
}
