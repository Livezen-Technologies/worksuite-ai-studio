import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { AcademicCapIcon, CpuChipIcon, PlayIcon, TableCellsIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, DataTable, Meter, PageHeader, StatCard, StatusBadge, type Column } from '../../components/ui';
import { get } from '../../lib/api';
import { ago, dateTime } from '../../lib/format';
import { PLACEMENT_LABEL, type Allocation, type JobNode, type PlacementStatus } from './types';

interface PortalJob { id: number; name: string; base_model: string; placement_status: PlacementStatus; dataset_name: string | null; created_at: string; nodes: JobNode[] }
interface OverviewData { allocations: Allocation[]; jobs: PortalJob[]; counts: { datasets: number; jobs: number; running: number } }

export default function PortalOverview() {
  const q = useQuery({ queryKey: ['portal', 'overview'], queryFn: () => get<OverviewData>('/portal/overview') });
  const d = q.data;

  const allocCols: Column<Allocation>[] = [
    { key: 'node', header: 'Node', cell: (a) => <div><div className="font-medium">{a.server_name}</div><div className="text-xs text-slate-500">{a.gpu_model ?? 'No GPU'}</div></div> },
    { key: 'status', header: 'Status', cell: (a) => <StatusBadge status={a.server_status} />, hideBelow: 'sm' },
    { key: 'cpu', header: 'CPU cores', cell: (a) => <Meter value={a.used_cpu_cores} max={Math.max(1, a.max_cpu_cores)} label={`${a.used_cpu_cores} / ${a.max_cpu_cores}`} /> },
    { key: 'gpu', header: 'GPUs', cell: (a) => <Meter value={a.used_gpus} max={Math.max(1, a.max_gpus)} label={`${a.used_gpus} / ${a.max_gpus}`} /> },
    { key: 'jobs', header: 'Jobs', cell: (a) => `${a.active_jobs} / ${a.max_concurrent_jobs}`, hideBelow: 'md' },
    { key: 'exp', header: 'Expires', cell: (a) => a.expired ? <Badge tone="red">Expired</Badge> : a.expires_at ? dateTime(a.expires_at) : 'Never', hideBelow: 'md' },
  ];
  const jobCols: Column<PortalJob>[] = [
    { key: 'name', header: 'Job', cell: (j) => <div><div className="font-medium">{j.name}</div><div className="text-xs text-slate-500">{j.base_model}</div></div> },
    { key: 'nodes', header: 'Nodes', cell: (j) => j.nodes.map((n) => n.server_name).join(', ') || '—', hideBelow: 'sm' },
    { key: 'st', header: 'Status', cell: (j) => <StatusBadge status={j.placement_status} label={PLACEMENT_LABEL[j.placement_status] ?? j.placement_status} /> },
    { key: 'at', header: 'Created', cell: (j) => ago(j.created_at), hideBelow: 'md' },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="My workspace" description="Your datasets, training jobs and the compute you have been allocated."
        actions={<Link to="/portal/jobs?new=1"><Button variant="primary" icon={PlayIcon}>New training job</Button></Link>} />
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard name="Datasets" value={d?.counts.datasets ?? '—'} icon={TableCellsIcon} loading={q.isLoading} />
        <StatCard name="Training jobs" value={d?.counts.jobs ?? '—'} icon={AcademicCapIcon} loading={q.isLoading} />
        <StatCard name="Running now" value={d?.counts.running ?? '—'} icon={CpuChipIcon} loading={q.isLoading} />
      </div>
      <Card>
        <CardHeader title="My compute allocations" description="What an administrator has allowed you to use. Usage counts jobs that are pending or running." />
        <DataTable columns={allocCols} rows={d?.allocations} rowKey={(a) => a.id} loading={q.isLoading} error={q.error} onRetry={q.refetch}
          empty="No nodes allocated to you yet. Ask an administrator." />
      </Card>
      <Card>
        <CardHeader title="Recent jobs" actions={<Link to="/portal/jobs" className="text-sm font-medium text-brand-600">All jobs</Link>} />
        <DataTable columns={jobCols} rows={d?.jobs} rowKey={(j) => j.id} loading={q.isLoading} empty="No training jobs yet." />
      </Card>
    </div>
  );
}
