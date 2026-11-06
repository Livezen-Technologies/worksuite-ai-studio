import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArchiveBoxIcon, BellAlertIcon, CircleStackIcon, PencilSquareIcon, PlusIcon, ServerIcon, TrashIcon, WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState, Meter, PageHeader, Segmented,
  StatCard, StatusBadge, useToast, type Column,
} from '../components/ui';
import { del, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, bytes, dateTime } from '../lib/format';
import { BackupsTab, FilesTab, PoliciesTab, TasksTab } from './StorageFiles';
import { LifecycleDialog } from './Storage/LifecycleDialog';
import { NodeDetail } from './Storage/NodeDetail';
import { NodeForm } from './Storage/NodeForm';
import { Rules } from './Storage/Rules';
import { ACTION, KIND, STATUS_TONE, n2, type Node, type Overview } from './Storage/shared';

export default function Storage() {
  const { can } = useAuth();
  const manage = can('storage.manage');
  const q = useQuery({ queryKey: ['storage'], queryFn: () => get<Overview>('/storage'), refetchInterval: 30_000 });
  const [editing, setEditing] = useState<Node | 'new' | null>(null);
  const [detail, setDetail] = useState<number | null>(null);
  const [removing, setRemoving] = useState<Node | null>(null);
  const [lifecycle, setLifecycle] = useState<{ node: Node; to: string } | null>(null);
  const [tab, setTab] = useState<'nodes' | 'files' | 'policies' | 'backups' | 'tasks'>('nodes');
  const qc = useQueryClient();
  const toast = useToast();
  const refresh = () => { qc.invalidateQueries({ queryKey: ['storage'] }); qc.invalidateQueries({ queryKey: ['storage-node'] }); };
  const test = useMutation({
    mutationFn: (n: Node) => post<Node>(`/storage/nodes/${n.id}/test`),
    onSuccess: (n) => { n.health === 'down' ? toast.error(`Check failed: ${n.last_error}`) : toast.success('Check passed', `${n2(n.write_mbps, 'MB/s')} write · ${n2(n.read_mbps, 'MB/s')} read · ${n2(n.latency_ms, 'ms')}`); refresh(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (n: Node) => del(`/storage/nodes/${n.id}`),
    onSuccess: () => { toast.success('Node removed'); setRemoving(null); refresh(); }, onError: (e) => toast.error(e),
  });
  const ack = useMutation({ mutationFn: (id: number) => post(`/storage/alerts/${id}/ack`), onSuccess: refresh, onError: (e) => toast.error(e) });

  const d = q.data;
  const s = d?.summary;
  const columns: Column<Node>[] = [
    {
      key: 'name', header: 'Node', cell: (n) => {
        const K = KIND[n.kind];
        return (
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-white/5"><K.icon className="size-5 text-slate-500" aria-hidden /></span>
            <div className="min-w-0">
              <p className="truncate font-medium text-slate-900 dark:text-white">{n.name}</p>
              <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                {n.kind === 's3' ? `${n.config.bucket} · ${n.config.endpoint?.replace(/^https?:\/\//, '')}` : n.kind === 'agent' ? `${d?.servers.find((v) => v.id === n.config.server_id)?.name ?? `server #${n.config.server_id}`}: ${n.config.path}` : n.config.path}{n.tags ? ` · ${n.tags}` : ''}
              </p>
            </div>
          </div>
        );
      },
    },
    { key: 'kind', header: 'Type', hideBelow: 'lg', cell: (n) => <Badge tone={KIND[n.kind].tone}>{KIND[n.kind].label}</Badge> },
    { key: 'status', header: 'Status', cell: (n) => <div className="flex flex-wrap gap-1.5"><Badge tone={STATUS_TONE[n.status]}>{n.status}</Badge><StatusBadge status={n.health} />{Number(n.readonly) === 1 && <Badge>read-only</Badge>}</div> },
    {
      key: 'capacity', header: 'Capacity', hideBelow: 'md', className: 'w-56', cell: (n) => n.capacity_bytes ? (
        <div>
          <Meter value={n.used_pct ?? 0} />
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{bytes(n.used_bytes ?? 0)} of {bytes(n.capacity_bytes)} · {n.used_pct}%{n.quota_bytes ? ' (quota)' : ''}</p>
        </div>
      ) : <span className="text-xs text-slate-500 dark:text-slate-400">{n.used_bytes != null ? `${bytes(n.used_bytes)} used · no quota set` : '—'}</span>,
    },
    {
      key: 'perf', header: 'Speed', hideBelow: 'lg', cell: (n) => (
        <div className="text-xs text-slate-500 dark:text-slate-400">
          <div>W {n2(n.write_mbps, 'MB/s')} · R {n2(n.read_mbps, 'MB/s')}</div>
          <div>{n2(n.latency_ms, 'ms')} response</div>
        </div>
      ),
    },
    { key: 'checked', header: 'Checked', hideBelow: 'sm', cell: (n) => <span className="text-xs text-slate-500 dark:text-slate-400" title={n.last_check_at ? dateTime(n.last_check_at) : ''}>{n.last_check_at ? ago(n.last_check_at) : 'never'}</span> },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (n) => (
        <div onClick={(e) => e.stopPropagation()}>
          <ActionMenu actions={[
            { label: 'Details', icon: CircleStackIcon, onClick: () => setDetail(n.id) },
            { label: 'Test now', icon: WrenchScrewdriverIcon, hidden: !manage, disabled: test.isPending, onClick: () => test.mutate(n) },
            { label: 'Edit', icon: PencilSquareIcon, hidden: !manage, onClick: () => setEditing(n) },
            ...(d?.flow[n.status] ?? []).map((to) => ({ label: ACTION[to], hidden: !manage, onClick: () => setLifecycle({ node: n, to }) })),
            { label: 'Remove', icon: TrashIcon, danger: true, hidden: !manage || !['testing', 'retired'].includes(n.status), onClick: () => setRemoving(n) },
          ]} />
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Storage" description="Local disks, network mounts and S3-compatible buckets that AI Studio stores datasets, recordings and models on — checked every 5 minutes."
        actions={manage && <Button icon={PlusIcon} variant="primary" onClick={() => setEditing('new')}>Add storage node</Button>} />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard name="Capacity" icon={CircleStackIcon} loading={q.isLoading} value={s ? bytes(s.capacity_bytes) : '—'} hint={s ? `${bytes(s.used_bytes)} used${s.capacity_bytes ? ` · ${Math.round((s.used_bytes / s.capacity_bytes) * 100)}%` : ''}` : undefined} />
        <StatCard name="Free" icon={ArchiveBoxIcon} loading={q.isLoading} value={s ? bytes(s.free_bytes) : '—'} hint="Across nodes that are not retired" />
        <StatCard name="Nodes" icon={ServerIcon} loading={q.isLoading} value={s ? `${s.active} active` : '—'} hint={s ? `${s.nodes} in total · ${s.healthy} healthy${s.down ? ` · ${s.down} down` : ''}` : undefined} />
        <StatCard name="Open alerts" icon={BellAlertIcon} loading={q.isLoading} value={s ? String(s.open_alerts) : '—'} hint={s?.open_alerts ? 'See below' : 'All clear'} />
      </div>

      {(d?.alerts.length ?? 0) > 0 && (
        <Card className="mb-6">
          <CardHeader title="Open alerts" description="They resolve on their own once a check no longer meets the rule." />
          <ul className="divide-y divide-slate-200 dark:divide-white/10">
            {d!.alerts.map((a) => (
              <li key={a.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
                <div className="flex min-w-0 flex-1 items-center gap-3">
                  <Badge tone={a.level === 'error' ? 'red' : 'amber'}>{a.level}</Badge>
                  <span className="min-w-0 text-sm text-slate-800 dark:text-slate-200">{a.message}</span>
                </div>
                <span className="text-xs text-slate-500 dark:text-slate-400">{ago(a.opened_at)}</span>
                {manage && (a.acknowledged_by ? <Badge>acknowledged</Badge> : <Button size="sm" variant="secondary" onClick={() => ack.mutate(a.id)}>Acknowledge</Button>)}
              </li>
            ))}
          </ul>
        </Card>
      )}

      <div className="mb-4">
        <Segmented label="Section" value={tab} onChange={setTab} options={[{ value: 'nodes', label: 'Nodes' }, { value: 'files', label: 'Files' }, { value: 'policies', label: 'Policies' }, { value: 'backups', label: 'Backups' }, { value: 'tasks', label: 'Background work' }]} />
      </div>
      {tab === 'files' && <FilesTab manage={manage} />}
      {tab === 'policies' && <PoliciesTab manage={manage} />}
      {tab === 'backups' && <BackupsTab manage={manage} />}
      {tab === 'tasks' && <TasksTab manage={manage} />}
      {tab === 'nodes' && <>
      <Card className="mb-6">
        <CardHeader title="Storage nodes" description="New nodes start in testing; activate one once its checks pass. Draining moves work away before a node is retired." />
        <DataTable columns={columns} rows={d?.nodes ?? []} rowKey={(n) => n.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()} onRowClick={(n) => setDetail(n.id)}
          empty={<EmptyState icon={CircleStackIcon} title="No storage nodes yet" description="Add a local disk, a network mount or an S3-compatible bucket to start." action={manage && <Button icon={PlusIcon} variant="primary" onClick={() => setEditing('new')}>Add storage node</Button>} />} />
      </Card>

      <Rules rules={d?.rules ?? []} nodes={d?.nodes ?? []} manage={manage} onChange={refresh} />
      </>}

      {editing && <NodeForm servers={d?.servers ?? []} node={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh(); }} />}
      {detail !== null && <NodeDetail id={detail} manage={manage} onClose={() => setDetail(null)} />}
      {lifecycle && <LifecycleDialog {...lifecycle} onClose={() => setLifecycle(null)} onDone={() => { setLifecycle(null); refresh(); }} />}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => { if (removing) remove.mutate(removing); }}
        title={`Remove ${removing?.name}?`} message="AI Studio stops using and checking it. Files already on it are not deleted." confirmLabel="Remove" busy={remove.isPending} tone="danger" />
    </>
  );
}
