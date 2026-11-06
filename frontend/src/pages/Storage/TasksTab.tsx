import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowPathIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, DataTable, EmptyState, Segmented, useToast, type Column } from '../../components/ui';
import { get, post } from '../../lib/api';
import { ago } from '../../lib/format';
import { TASK_TONE, type Task } from './shared';

export function TasksTab({ manage }: { manage: boolean }) {
  const toast = useToast();
  const [status, setStatus] = useState('');
  const q = useQuery({ queryKey: ['storage-tasks', status], queryFn: () => get<{ tasks: Task[]; counts: Record<string, number> }>('/storage/tasks', { status }), refetchInterval: 10_000 });
  const retry = useMutation({ mutationFn: (t: Task) => post(`/storage/tasks/${t.id}/retry`), onSuccess: () => { toast.success('Queued again'); q.refetch(); }, onError: (e) => toast.error(e) });
  const c = q.data?.counts ?? {};
  const columns: Column<Task>[] = [
    { key: 'type', header: 'Work', cell: (t) => <span className="font-medium text-slate-900 dark:text-white">{t.type}</span> },
    { key: 'file', header: 'File', cell: (t) => <span className="truncate text-sm">{t.app}/{t.object_key}</span> },
    { key: 'nodes', header: 'Nodes', hideBelow: 'md', cell: (t) => <span className="text-xs">{t.from_name ?? '—'} → {t.to_name ?? (t.type === 'delete' ? '' : 'best node')}</span> },
    { key: 'status', header: 'Status', cell: (t) => <span className="flex flex-col gap-0.5"><Badge tone={TASK_TONE[t.status]}>{t.status}{t.attempts ? ` · ${t.attempts} tries` : ''}</Badge>{t.error && <span className="text-xs text-red-600 dark:text-red-400">{t.error}</span>}</span> },
    { key: 'when', header: 'Updated', hideBelow: 'sm', cell: (t) => <span className="text-xs text-slate-500 dark:text-slate-400">{ago(t.updated_at)}</span> },
    { key: 'retry', header: <span className="sr-only">Retry</span>, align: 'right', cell: (t) => manage && t.status === 'failed' ? <Button size="sm" variant="secondary" icon={ArrowPathIcon} onClick={() => retry.mutate(t)}>Retry</Button> : null },
  ];
  return (
    <Card>
      <CardHeader title="Background work" description="Copies, moves and deletions run every minute. Failed work is retried 5 times before it stops here."
        actions={<Segmented label="Status" value={status || 'all'} onChange={(v) => setStatus(v === 'all' ? '' : v)} options={[
          { value: 'all', label: 'All' }, { value: 'pending', label: `Waiting ${c.pending ?? 0}` }, { value: 'failed', label: `Failed ${c.failed ?? 0}` }, { value: 'done', label: 'Done' },
        ]} />} />
      <DataTable columns={columns} rows={q.data?.tasks ?? []} rowKey={(t) => t.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()}
        empty={<EmptyState icon={ArrowPathIcon} title="Nothing queued" description="Every file has the copies its policy asks for." />} />
    </Card>
  );
}
