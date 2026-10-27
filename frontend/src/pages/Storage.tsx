import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArchiveBoxIcon, BellAlertIcon, CircleStackIcon, CloudIcon, PencilSquareIcon, PlusIcon, ServerIcon, ShareIcon, TrashIcon, WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState, FormModal, Input, Meter, Modal, PageHeader, Segmented,
  Select, StatCard, StatusBadge, Toggle, useToast, type Column,
} from '../components/ui';
import { del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, bytes, dateTime } from '../lib/format';

type Kind = 'local' | 'mounted' | 's3';
interface Node {
  id: number; name: string; kind: Kind; status: 'testing' | 'active' | 'draining' | 'retired'; health: 'ok' | 'degraded' | 'down' | 'unknown';
  config: { path?: string; endpoint?: string; bucket?: string; region?: string; path_style?: boolean; prefix?: string };
  has_secret: boolean; region: string | null; tags: string | null; priority: number; readonly: number | string;
  quota_bytes: number | null; total_bytes: number | null; used_bytes: number | null; free_bytes: number | null; capacity_bytes: number | null; used_pct: number | null;
  write_mbps: number | null; read_mbps: number | null; latency_ms: number | null; last_check_at: string | null; last_error: string | null;
  status_history: { from: string | null; to: string; at: string; reason?: string | null }[]; allocations?: number;
}
interface Rule { id: number; name: string; metric: string; op: 'gt' | 'lt'; threshold: number | string; level: 'warning' | 'error'; node_id: number | null; enabled: number | string }
interface AlertRow { id: number; node_id: number; node_name: string | null; level: string; message: string; opened_at: string; acknowledged_by: number | null }
interface Overview {
  summary: { nodes: number; active: number; healthy: number; down: number; capacity_bytes: number; used_bytes: number; free_bytes: number; open_alerts: number };
  nodes: Node[]; alerts: AlertRow[]; rules: Rule[]; flow: Record<string, string[]>;
}
interface Check { at: string; health: string; used_bytes: number | null; total_bytes: number | null; write_mbps: number | null; read_mbps: number | null; latency_ms: number | null; error: string | null }
interface Allocation { id: number; scope_type: string; scope_id: string; quota_bytes: number | null; note: string | null; created_at: string }

const KIND: Record<Kind, { label: string; icon: typeof ServerIcon; tone: 'blue' | 'purple' | 'amber' }> = {
  local: { label: 'Local disk', icon: ServerIcon, tone: 'blue' },
  mounted: { label: 'Network mount', icon: ShareIcon, tone: 'purple' },
  s3: { label: 'S3-compatible', icon: CloudIcon, tone: 'amber' },
};
const STATUS_TONE: Record<string, 'gray' | 'green' | 'amber' | 'blue'> = { testing: 'blue', active: 'green', draining: 'amber', retired: 'gray' };
const ACTION: Record<string, string> = { active: 'Activate', draining: 'Drain', retired: 'Retire', testing: 'Return to testing' };
const METRICS: Record<string, string> = { used_pct: 'Used space (%)', free_gb: 'Free space (GB)', latency_ms: 'Response time (ms)', write_mbps: 'Write speed (MB/s)', down: 'Node unreachable' };
const n2 = (v: number | null | undefined, unit: string) => (v == null ? '—' : `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })} ${unit}`);

export default function Storage() {
  const { can } = useAuth();
  const manage = can('storage.manage');
  const q = useQuery({ queryKey: ['storage'], queryFn: () => get<Overview>('/storage'), refetchInterval: 30_000 });
  const [editing, setEditing] = useState<Node | 'new' | null>(null);
  const [detail, setDetail] = useState<number | null>(null);
  const [removing, setRemoving] = useState<Node | null>(null);
  const [lifecycle, setLifecycle] = useState<{ node: Node; to: string } | null>(null);
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
                {n.kind === 's3' ? `${n.config.bucket} · ${n.config.endpoint?.replace(/^https?:\/\//, '')}` : n.config.path}{n.tags ? ` · ${n.tags}` : ''}
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

      <Card className="mb-6">
        <CardHeader title="Storage nodes" description="New nodes start in testing; activate one once its checks pass. Draining moves work away before a node is retired." />
        <DataTable columns={columns} rows={d?.nodes ?? []} rowKey={(n) => n.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()} onRowClick={(n) => setDetail(n.id)}
          empty={<EmptyState icon={CircleStackIcon} title="No storage nodes yet" description="Add a local disk, a network mount or an S3-compatible bucket to start." action={manage && <Button icon={PlusIcon} variant="primary" onClick={() => setEditing('new')}>Add storage node</Button>} />} />
      </Card>

      <Rules rules={d?.rules ?? []} nodes={d?.nodes ?? []} manage={manage} onChange={refresh} />

      {editing && <NodeForm node={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh(); }} />}
      {detail !== null && <NodeDetail id={detail} manage={manage} onClose={() => setDetail(null)} />}
      {lifecycle && <LifecycleDialog {...lifecycle} onClose={() => setLifecycle(null)} onDone={() => { setLifecycle(null); refresh(); }} />}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => { if (removing) remove.mutate(removing); }}
        title={`Remove ${removing?.name}?`} message="AI Studio stops using and checking it. Files already on it are not deleted." confirmLabel="Remove" busy={remove.isPending} tone="danger" />
    </>
  );
}

function LifecycleDialog({ node, to, onClose, onDone }: { node: Node; to: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const toast = useToast();
  const go = useMutation({ mutationFn: () => post(`/storage/nodes/${node.id}/status`, { to, reason }), onSuccess: () => { toast.success(`${node.name}: ${to}`); onDone(); }, onError: (e) => toast.error(e) });
  const text: Record<string, string> = {
    active: 'The node starts receiving new data.',
    draining: 'The node stops receiving new data; files on it stay readable while they are moved elsewhere.',
    retired: 'The node is no longer used or checked. Files on it are not deleted.',
    testing: 'The node goes back to testing; it is checked but receives no data.',
  };
  return (
    <FormModal open onClose={onClose} title={`${ACTION[to]} ${node.name}`} description={text[to]} submitLabel={ACTION[to]} submitting={go.isPending} onSubmit={() => go.mutate()}>
      <Input label="Reason (optional)" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Moving to a larger disk" />
    </FormModal>
  );
}

function NodeForm({ node, onClose, onSaved }: { node: Node | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [kind, setKind] = useState<Kind>(node?.kind ?? 'local');
  const [name, setName] = useState(node?.name ?? '');
  const [path, setPath] = useState(node?.config.path ?? '');
  const [endpoint, setEndpoint] = useState(node?.config.endpoint ?? '');
  const [bucket, setBucket] = useState(node?.config.bucket ?? '');
  const [s3region, setS3region] = useState(node?.config.region ?? '');
  const [pathStyle, setPathStyle] = useState(node?.config.path_style ?? true);
  const [prefix, setPrefix] = useState(node?.config.prefix ?? 'aistudio');
  const [accessKey, setAccessKey] = useState('');
  const [secretKey, setSecretKey] = useState('');
  const [quota, setQuota] = useState(node?.quota_bytes ? String(Math.round(node.quota_bytes / 1073741824)) : '');
  const [region, setRegion] = useState(node?.region ?? '');
  const [tags, setTags] = useState(node?.tags ?? '');
  const [priority, setPriority] = useState(String(node?.priority ?? 100));
  const [readonly, setReadonly] = useState(Number(node?.readonly ?? 0) === 1);
  const save = useMutation({
    mutationFn: () => {
      const body = {
        name, kind, quota_gb: quota, region, tags, priority: Number(priority) || 100, readonly,
        config: kind === 's3' ? { endpoint, bucket, region: s3region, path_style: pathStyle, prefix } : { path },
        secret: kind === 's3' ? { access_key: accessKey, secret_key: secretKey } : undefined,
      };
      return node ? put<Node>(`/storage/nodes/${node.id}`, body) : post<Node>('/storage/nodes', body);
    },
    onSuccess: (n) => {
      if (n.health === 'down') toast.error(`Saved, but the check failed: ${n.last_error}`);
      else toast.success(node ? 'Saved and checked' : 'Node added and checked', `${n2(n.write_mbps, 'MB/s')} write · ${n2(n.read_mbps, 'MB/s')} read`);
      onSaved();
    },
    onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} size="lg" title={node ? `Edit ${node.name}` : 'Add a storage node'}
      description="Saving runs a check: it writes, reads back and deletes a 4 MB test file, and measures capacity and speed."
      submitLabel={node ? 'Save and check' : 'Add and check'} submitting={save.isPending} onSubmit={() => save.mutate()}>
      <div className="grid gap-4 sm:grid-cols-2">
        {!node && (
          <div className="sm:col-span-2">
            <Segmented label="Type" value={kind} onChange={setKind}
              options={[{ value: 'local', label: 'Local disk' }, { value: 'mounted', label: 'Network mount' }, { value: 's3', label: 'S3-compatible' }]} />
          </div>
        )}
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder={kind === 's3' ? 'Garage — primary' : 'Data disk 2'} wrapperClassName="sm:col-span-2" />
        {kind !== 's3' ? (
          <Input label="Folder on this server" required value={path} onChange={(e) => setPath(e.target.value)} placeholder={kind === 'mounted' ? '/mnt/nas-storage' : '/srv/storage'} wrapperClassName="sm:col-span-2"
            hint={kind === 'mounted'
              ? 'The mount point of an NFS or SMB share (mount it on the server first, e.g. in /etc/fstab). The check refuses it while the share is not mounted.'
              : 'A dedicated folder on a disk of this server, writable by www-data.'} />
        ) : <>
          <Input label="Endpoint" required value={endpoint} onChange={(e) => setEndpoint(e.target.value)} placeholder="https://s3.eu-central-1.amazonaws.com" wrapperClassName="sm:col-span-2" />
          <Input label="Bucket" required value={bucket} onChange={(e) => setBucket(e.target.value)} placeholder="aistudio-data" />
          <Input label="Region" value={s3region} onChange={(e) => setS3region(e.target.value)} placeholder="us-east-1 (Garage: garage)" />
          <Input label="Access key" value={accessKey} onChange={(e) => setAccessKey(e.target.value)} autoComplete="off" placeholder={node?.has_secret ? 'Stored — leave empty to keep' : ''} />
          <Input label="Secret key" type="password" value={secretKey} onChange={(e) => setSecretKey(e.target.value)} autoComplete="new-password" placeholder={node?.has_secret ? 'Stored — leave empty to keep' : ''} />
          <Input label="Folder in the bucket" value={prefix} onChange={(e) => setPrefix(e.target.value)} hint="Everything AI Studio stores goes under this prefix." />
          <div className="flex items-end pb-1"><Toggle checked={pathStyle} onChange={setPathStyle} label="Path-style URLs" description="On for Garage and MinIO; off for AWS virtual-hosted buckets." /></div>
          <p className="text-xs/5 text-slate-500 sm:col-span-2 dark:text-slate-400">Keys are stored encrypted and never shown again.</p>
        </>}
        <Input label="Quota (GB)" type="number" value={quota} onChange={(e) => setQuota(e.target.value)} placeholder={kind === 's3' ? 'e.g. 500' : 'the whole disk'}
          hint={kind === 's3' ? 'Buckets have no size of their own; set how much AI Studio may use.' : 'Optional cap below the disk size.'} />
        <Input label="Priority" type="number" value={priority} onChange={(e) => setPriority(e.target.value)} hint="Lower is preferred when placing new data." />
        <Input label="Region / site" value={region} onChange={(e) => setRegion(e.target.value)} placeholder="Colombo DC" />
        <Input label="Tags" value={tags} onChange={(e) => setTags(e.target.value)} placeholder="fast, backups" />
        <div className="sm:col-span-2"><Toggle checked={readonly} onChange={setReadonly} label="Read-only" description="Checked and readable, never written to (e.g. an archive)." /></div>
      </div>
    </FormModal>
  );
}

function Spark({ values, max, color }: { values: (number | null)[]; max?: number; color: string }) {
  const v = values.map((x) => x ?? 0);
  const top = max ?? Math.max(1, ...v);
  if (v.length < 2) return <p className="text-xs text-slate-400">Not enough checks yet.</p>;
  const pts = v.map((x, i) => `${(i / (v.length - 1)) * 100},${38 - (x / top) * 34}`).join(' ');
  return <svg viewBox="0 0 100 40" preserveAspectRatio="none" className="h-16 w-full" aria-hidden><polyline points={pts} fill="none" stroke={color} strokeWidth="1.2" vectorEffect="non-scaling-stroke" /></svg>;
}

function NodeDetail({ id, manage, onClose }: { id: number; manage: boolean; onClose: () => void }) {
  const q = useQuery({ queryKey: ['storage-node', id], queryFn: () => get<{ node: Node; checks: Check[]; allocations: Allocation[]; alerts: (AlertRow & { resolved_at: string | null })[] }>(`/storage/nodes/${id}`) });
  const qc = useQueryClient();
  const toast = useToast();
  const [scopeType, setScopeType] = useState('app');
  const [scopeId, setScopeId] = useState('');
  const [quota, setQuota] = useState('');
  const add = useMutation({
    mutationFn: () => post(`/storage/nodes/${id}/allocations`, { scope_type: scopeType, scope_id: scopeId, quota_gb: quota }),
    onSuccess: () => { setScopeId(''); setQuota(''); qc.invalidateQueries({ queryKey: ['storage-node', id] }); qc.invalidateQueries({ queryKey: ['storage'] }); },
    onError: (e) => toast.error(e),
  });
  const drop = useMutation({ mutationFn: (a: number) => del(`/storage/allocations/${a}`), onSuccess: () => qc.invalidateQueries({ queryKey: ['storage-node', id] }), onError: (e) => toast.error(e) });
  const n = q.data?.node;
  const checks = q.data?.checks ?? [];
  return (
    <Modal open onClose={onClose} size="xl" expandKey="storage-node" title={n ? <span className="flex flex-wrap items-center gap-2">{n.name}<Badge tone={STATUS_TONE[n.status]}>{n.status}</Badge><StatusBadge status={n.health} /></span> : 'Storage node'}
      description={n ? `${KIND[n.kind].label} · ${n.kind === 's3' ? `${n.config.bucket} at ${n.config.endpoint}` : n.config.path}` : undefined}>
      {!n ? <p className="text-sm text-slate-500">Loading…</p> : (
        <div className="space-y-6">
          {n.last_error && n.health === 'down' && <Alert tone="danger" title="Last check failed">{n.last_error}</Alert>}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[['Used', n.capacity_bytes ? `${bytes(n.used_bytes ?? 0)} (${n.used_pct}%)` : n.used_bytes != null ? bytes(n.used_bytes) : '—'], ['Free', n.free_bytes != null ? bytes(n.free_bytes) : n.capacity_bytes && n.used_bytes != null ? bytes(n.capacity_bytes - n.used_bytes) : '—'],
              ['Write / read', `${n2(n.write_mbps, '')}/ ${n2(n.read_mbps, 'MB/s')}`], ['Response', n2(n.latency_ms, 'ms')]].map(([k, v]) => (
              <div key={k} className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                <div className="text-xs text-slate-500 dark:text-slate-400">{k}</div><div className="mt-1 text-sm font-semibold break-words text-slate-900 dark:text-white">{v}</div>
              </div>
            ))}
          </div>
          <div className="grid gap-6 lg:grid-cols-3">
            <Card><CardHeader title="Used space, 7 days" /><div className="p-4"><Spark values={checks.map((c) => (c.used_bytes && (n.quota_bytes || c.total_bytes) ? (c.used_bytes / (n.quota_bytes || c.total_bytes!)) * 100 : null))} max={100} color="#0A6CFF" /></div></Card>
            <Card><CardHeader title="Write speed (MB/s)" /><div className="p-4"><Spark values={checks.map((c) => c.write_mbps)} color="#00DAF2" /></div></Card>
            <Card><CardHeader title="Response time (ms)" /><div className="p-4"><Spark values={checks.map((c) => c.latency_ms)} color="#f59e0b" /></div></Card>
          </div>
          <Card>
            <CardHeader title="Allocations" description="Which apps, users, services or organizations this node is assigned to, with an optional quota each." />
            <ul className="divide-y divide-slate-200 dark:divide-white/10">
              {(q.data?.allocations ?? []).map((a) => (
                <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm sm:px-6">
                  <span><Badge>{a.scope_type}</Badge> <span className="ml-2 font-medium text-slate-900 dark:text-white">{a.scope_id}</span>{a.quota_bytes ? <span className="ml-2 text-slate-500">· {bytes(a.quota_bytes)}</span> : null}</span>
                  {manage && <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => drop.mutate(a.id)}>Remove</Button>}
                </li>
              ))}
              {!(q.data?.allocations.length) && <li className="px-4 py-3 text-sm text-slate-500 sm:px-6">Not allocated — available to everything.</li>}
            </ul>
            {manage && (
              <div className="grid gap-3 border-t border-slate-200 p-4 sm:grid-cols-4 sm:px-6 dark:border-white/10">
                <Select label="Assign to" value={scopeType} onChange={(e) => setScopeType(e.target.value)} options={[{ value: 'app', label: 'App' }, { value: 'service', label: 'Service' }, { value: 'user', label: 'User' }, { value: 'organization', label: 'Organization' }]} />
                <Input label="Which" value={scopeId} onChange={(e) => setScopeId(e.target.value)} placeholder={scopeType === 'app' ? 'datasets, speech, models…' : scopeType === 'user' ? 'email or user id' : 'name'} />
                <Input label="Quota (GB)" type="number" value={quota} onChange={(e) => setQuota(e.target.value)} placeholder="optional" />
                <div className="flex items-end"><Button variant="primary" className="w-full" loading={add.isPending} disabled={!scopeId.trim()} onClick={() => add.mutate()}>Allocate</Button></div>
              </div>
            )}
          </Card>
          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <CardHeader title="Alert history" />
              <ul className="max-h-64 divide-y divide-slate-200 overflow-auto dark:divide-white/10">
                {(q.data?.alerts ?? []).map((a) => (
                  <li key={a.id} className="px-4 py-2.5 text-sm sm:px-6">
                    <Badge tone={a.level === 'error' ? 'red' : 'amber'}>{a.level}</Badge> <span className="text-slate-700 dark:text-slate-300">{a.message}</span>
                    <p className="text-xs text-slate-500">{dateTime(a.opened_at)}{a.resolved_at ? ` — resolved ${ago(a.resolved_at)}` : ' — open'}</p>
                  </li>
                ))}
                {!(q.data?.alerts.length) && <li className="px-4 py-3 text-sm text-slate-500 sm:px-6">No alerts.</li>}
              </ul>
            </Card>
            <Card>
              <CardHeader title="Lifecycle" />
              <ul className="max-h-64 divide-y divide-slate-200 overflow-auto dark:divide-white/10">
                {[...n.status_history].reverse().map((h, i) => (
                  <li key={i} className="px-4 py-2.5 text-sm sm:px-6"><span className="font-medium text-slate-900 dark:text-white">{h.from ? `${h.from} → ${h.to}` : h.to}</span>
                    <p className="text-xs text-slate-500">{dateTime(h.at)}{h.reason ? ` — ${h.reason}` : ''}</p></li>
                ))}
              </ul>
            </Card>
          </div>
        </div>
      )}
    </Modal>
  );
}

function Rules({ rules, nodes, manage, onChange }: { rules: Rule[]; nodes: Node[]; manage: boolean; onChange: () => void }) {
  const toast = useToast();
  const [editing, setEditing] = useState<Rule | 'new' | null>(null);
  const toggle = useMutation({ mutationFn: (r: Rule) => put(`/storage/rules/${r.id}`, { ...r, enabled: Number(r.enabled) !== 1 }), onSuccess: onChange, onError: (e) => toast.error(e) });
  const remove = useMutation({ mutationFn: (r: Rule) => del(`/storage/rules/${r.id}`), onSuccess: () => { toast.success('Rule deleted'); onChange(); }, onError: (e) => toast.error(e) });
  return (
    <Card>
      <CardHeader title="Alert rules" description="Checked after every health check. Errors also go to the configured Slack / email / webhook channels."
        actions={manage && <Button size="sm" icon={PlusIcon} onClick={() => setEditing('new')}>Add rule</Button>} />
      <ul className="divide-y divide-slate-200 dark:divide-white/10">
        {rules.map((r) => (
          <li key={r.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-slate-900 dark:text-white">{r.name} <Badge tone={r.level === 'error' ? 'red' : 'amber'}>{r.level}</Badge></p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {r.metric === 'down' ? 'When a node fails its check' : `${METRICS[r.metric]} ${r.op === 'gt' ? 'above' : 'below'} ${Number(r.threshold)}`} · {r.node_id ? nodes.find((n) => n.id === r.node_id)?.name ?? `node #${r.node_id}` : 'every node'}
              </p>
            </div>
            {manage && (
              <div className="flex items-center gap-2">
                <Toggle checked={Number(r.enabled) === 1} onChange={() => toggle.mutate(r)} label="On" />
                <Button size="sm" variant="secondary" icon={PencilSquareIcon} onClick={() => setEditing(r)}>Edit</Button>
                <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => remove.mutate(r)}>Delete</Button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && <RuleForm rule={editing === 'new' ? null : editing} nodes={nodes} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); onChange(); }} />}
    </Card>
  );
}

function RuleForm({ rule, nodes, onClose, onSaved }: { rule: Rule | null; nodes: Node[]; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [name, setName] = useState(rule?.name ?? '');
  const [metric, setMetric] = useState(rule?.metric ?? 'used_pct');
  const [op, setOp] = useState<'gt' | 'lt'>(rule?.op ?? 'gt');
  const [threshold, setThreshold] = useState(String(rule?.threshold ?? 85));
  const [level, setLevel] = useState<'warning' | 'error'>(rule?.level ?? 'warning');
  const [nodeId, setNodeId] = useState(String(rule?.node_id ?? ''));
  const save = useMutation({
    mutationFn: () => {
      const body = { name, metric, op, threshold: Number(threshold), level, node_id: Number(nodeId) || null, enabled: rule ? Number(rule.enabled) === 1 : true };
      return rule ? put(`/storage/rules/${rule.id}`, body) : post('/storage/rules', body);
    },
    onSuccess: () => { toast.success('Rule saved'); onSaved(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={rule ? 'Edit alert rule' : 'Add an alert rule'} submitLabel="Save rule" submitting={save.isPending} onSubmit={() => save.mutate()}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Disk 85% full" wrapperClassName="sm:col-span-2" />
        <Select label="When" value={metric} onChange={(e) => setMetric(e.target.value)} options={Object.entries(METRICS).map(([v, l]) => ({ value: v, label: l }))} wrapperClassName="sm:col-span-2" />
        {metric !== 'down' && <>
          <Select label="Is" value={op} onChange={(e) => setOp(e.target.value as 'gt' | 'lt')} options={[{ value: 'gt', label: 'above' }, { value: 'lt', label: 'below' }]} />
          <Input label="Threshold" type="number" step="any" value={threshold} onChange={(e) => setThreshold(e.target.value)} />
        </>}
        <Select label="Level" value={level} onChange={(e) => setLevel(e.target.value as 'warning' | 'error')} options={[{ value: 'warning', label: 'Warning' }, { value: 'error', label: 'Error (also sent to channels)' }]} />
        <Select label="Nodes" value={nodeId} onChange={(e) => setNodeId(e.target.value)} options={[{ value: '', label: 'Every node' }, ...nodes.map((n) => ({ value: String(n.id), label: n.name }))]} />
      </div>
    </FormModal>
  );
}

