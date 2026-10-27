import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownTrayIcon, ArrowPathIcon, ArrowUpTrayIcon, DocumentIcon, FolderIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { ActionMenu, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState, FormModal, Input, Modal, Segmented, Select, useToast, type Column } from '../components/ui';
import { del, download, get, post, put } from '../lib/api';
import { ago, bytes, dateTime } from '../lib/format';

interface FileRow { id: number; app: string; object_key: string; name: string; size: number; mime: string | null; created_at: string; copies: number | string }
interface Listing { apps: { app: string; files: number; bytes: number }[]; app: string; prefix: string; folders: { name: string; files: number; bytes: number }[]; files: FileRow[] }
interface Placement { node_id: number; node_name: string | null; status: string; health: string | null; node_status: string | null; verified_at: string | null; error: string | null }
interface Task { id: number; type: string; status: string; object_key: string | null; app: string | null; from_name: string | null; to_name: string | null; attempts: number; error: string | null; reason: string | null; updated_at: string }
interface FileDetail extends FileRow { sha256: string; placements: Placement[]; tasks: Task[]; policy: { name?: string; replicas: number | string; strategy: string } }
export interface Policy { id: number; name: string; replicas: number | string; min_regions?: number | string; strategy: string; node_tags: string | null; app: string | null; is_default: number | string; files?: number | string }

const STRATEGY: Record<string, string> = { capacity: 'Most free space', priority: 'Node priority', round_robin: 'Spread evenly' };
const TASK_TONE: Record<string, 'blue' | 'amber' | 'green' | 'red'> = { pending: 'blue', running: 'amber', done: 'green', failed: 'red' };

export function FilesTab({ manage }: { manage: boolean }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [app, setApp] = useState('');
  const [prefix, setPrefix] = useState('');
  const [search, setSearch] = useState('');
  const [detail, setDetail] = useState<number | null>(null);
  const [moving, setMoving] = useState<FileRow | null>(null);
  const [removing, setRemoving] = useState<FileRow | null>(null);
  const [uploadApp, setUploadApp] = useState('files');
  const fileInput = useRef<HTMLInputElement>(null);
  const q = useQuery({ queryKey: ['storage-files', app, prefix, search], queryFn: () => get<Listing>('/storage/files', { app, prefix, q: search }) });
  const d = q.data;
  const refresh = () => qc.invalidateQueries({ queryKey: ['storage-files'] });
  const upload = useMutation({
    mutationFn: async (files: FileList) => {
      for (const f of Array.from(files)) {
        const fd = new FormData();
        fd.append('file', f); fd.append('app', d?.app || uploadApp); fd.append('folder', prefix);
        await post('/storage/files', fd);
      }
    },
    onSuccess: () => { toast.success('Uploaded', 'Extra copies are made in the background.'); refresh(); },
    onError: (e) => { toast.error(e); refresh(); },
  });
  const remove = useMutation({ mutationFn: (f: FileRow) => del(`/storage/files/${f.id}`), onSuccess: () => { toast.success('Deleted'); setRemoving(null); refresh(); }, onError: (e) => toast.error(e) });
  const crumbs = prefix ? prefix.split('/') : [];

  const columns: Column<FileRow>[] = [
    { key: 'name', header: 'Name', cell: (f) => <span className="flex items-center gap-2 font-medium text-slate-900 dark:text-white"><DocumentIcon className="size-4 shrink-0 text-slate-400" /><span className="truncate">{search ? f.object_key : f.name}</span></span> },
    { key: 'size', header: 'Size', align: 'right', cell: (f) => <span className="tabular-nums">{bytes(f.size)}</span> },
    { key: 'copies', header: 'Copies', hideBelow: 'sm', cell: (f) => <Badge tone={Number(f.copies) === 0 ? 'red' : 'gray'}>{f.copies}</Badge> },
    { key: 'added', header: 'Added', hideBelow: 'md', cell: (f) => <span className="text-xs text-slate-500 dark:text-slate-400" title={dateTime(f.created_at)}>{ago(f.created_at)}</span> },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (f) => (
        <div onClick={(e) => e.stopPropagation()}>
          <ActionMenu actions={[
            { label: 'Download', icon: ArrowDownTrayIcon, onClick: () => download(`/storage/files/${f.id}/download`, f.name).catch((e) => toast.error(e)) },
            { label: 'Rename or move', icon: PencilSquareIcon, hidden: !manage, onClick: () => setMoving(f) },
            { label: 'Delete', icon: TrashIcon, danger: true, hidden: !manage, onClick: () => setRemoving(f) },
          ]} />
        </div>
      ),
    },
  ];

  return (
    <Card>
      <CardHeader title="Files" description="Each file is kept on as many nodes as its policy asks. Reads fall over to another copy if a node is down or a copy is damaged."
        actions={manage && (
          <div className="flex items-center gap-2">
            {!d?.apps.length && <Input label="App" value={uploadApp} onChange={(e) => setUploadApp(e.target.value)} className="w-28" />}
            <input ref={fileInput} type="file" multiple hidden onChange={(e) => { if (e.target.files?.length) upload.mutate(e.target.files); e.target.value = ''; }} />
            <Button size="sm" variant="primary" icon={ArrowUpTrayIcon} disabled={upload.isPending} onClick={() => fileInput.current?.click()}>{upload.isPending ? 'Uploading…' : 'Upload'}</Button>
          </div>
        )} />
      <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:px-6 dark:border-white/10">
        {(d?.apps.length ?? 0) > 0 && (
          <Select label="App" value={d?.app ?? ''} onChange={(e) => { setApp(e.target.value); setPrefix(''); }}
            options={d!.apps.map((a) => ({ value: a.app, label: `${a.app} — ${a.files} files, ${bytes(a.bytes)}` }))} />
        )}
        <nav className="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm" aria-label="Folder">
          <button type="button" className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setPrefix('')}>{d?.app || 'files'}</button>
          {crumbs.map((c, i) => (
            <span key={i} className="flex items-center gap-1"><span className="text-slate-400">/</span>
              <button type="button" className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setPrefix(crumbs.slice(0, i + 1).join('/'))}>{c}</button></span>
          ))}
        </nav>
        <Input label="Search" placeholder="Search paths" value={search} onChange={(e) => setSearch(e.target.value)} className="sm:w-56" />
      </div>
      {!search && (d?.folders.length ?? 0) > 0 && (
        <ul className="divide-y divide-slate-200 dark:divide-white/10">
          {d!.folders.map((f) => (
            <li key={f.name}>
              <button type="button" onClick={() => setPrefix(prefix ? `${prefix}/${f.name}` : f.name)} className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm hover:bg-slate-50 sm:px-6 dark:hover:bg-white/5">
                <FolderIcon className="size-4 text-amber-500" /><span className="flex-1 font-medium text-slate-900 dark:text-white">{f.name}</span>
                <span className="text-xs text-slate-500 tabular-nums dark:text-slate-400">{f.files} files · {bytes(f.bytes)}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      <DataTable columns={columns} rows={d?.files ?? []} rowKey={(f) => f.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()} onRowClick={(f) => setDetail(f.id)}
        empty={(d?.folders.length ?? 0) > 0 ? undefined : <EmptyState icon={FolderIcon} title="No files here" description="Upload files, or let apps store them here. Activate a storage node first." />} />
      {detail !== null && <FileDetailModal id={detail} manage={manage} onClose={() => setDetail(null)} onChanged={refresh} />}
      {moving && <MoveForm file={moving} onClose={() => setMoving(null)} onSaved={() => { setMoving(null); refresh(); }} />}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => { if (removing) remove.mutate(removing); }}
        title={`Delete ${removing?.name}?`} message="The file disappears at once and every copy is removed from the nodes in the background." confirmLabel="Delete" busy={remove.isPending} tone="danger" />
    </Card>
  );
}

function MoveForm({ file, onClose, onSaved }: { file: FileRow; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [key, setKey] = useState(file.object_key);
  const save = useMutation({ mutationFn: () => put(`/storage/files/${file.id}`, { key }), onSuccess: () => { toast.success('Moved'); onSaved(); }, onError: (e) => toast.error(e) });
  return (
    <FormModal open onClose={onClose} title="Rename or move" description="Changes the path only — the copies on the nodes stay where they are." onSubmit={() => save.mutate()} submitting={save.isPending}>
      <Input label="Path" value={key} onChange={(e) => setKey(e.target.value)} hint="Folders are separated by /" required />
    </FormModal>
  );
}

function FileDetailModal({ id, manage, onClose, onChanged }: { id: number; manage: boolean; onClose: () => void; onChanged: () => void }) {
  const toast = useToast();
  const q = useQuery({ queryKey: ['storage-file', id], queryFn: () => get<FileDetail>(`/storage/files/${id}`), refetchInterval: 10_000 });
  const f = q.data;
  const move = useMutation({
    mutationFn: (from: number) => post(`/storage/files/${id}/transfer`, { from_node: from }),
    onSuccess: () => { toast.success('Move queued', 'It runs within a minute.'); q.refetch(); onChanged(); }, onError: (e) => toast.error(e),
  });
  return (
    <Modal open onClose={onClose} title={f?.name ?? 'File'} description={f?.object_key} size="lg">
      {f && (
        <div className="space-y-5 text-sm">
          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div><dt className="text-xs text-slate-500 dark:text-slate-400">Size</dt><dd className="tabular-nums">{bytes(f.size)}</dd></div>
            <div><dt className="text-xs text-slate-500 dark:text-slate-400">Policy</dt><dd>{f.policy.replicas} copies · {STRATEGY[f.policy.strategy] ?? f.policy.strategy}</dd></div>
            <div><dt className="text-xs text-slate-500 dark:text-slate-400">Added</dt><dd>{dateTime(f.created_at)}</dd></div>
            <div><dt className="text-xs text-slate-500 dark:text-slate-400">SHA-256</dt><dd className="truncate font-mono text-xs" title={f.sha256}>{f.sha256.slice(0, 16)}…</dd></div>
          </dl>
          <div>
            <h3 className="mb-2 font-medium text-slate-900 dark:text-white">Copies</h3>
            <ul className="divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-white/10 dark:border-white/10">
              {f.placements.map((p) => (
                <li key={p.node_id} className="flex items-center gap-3 px-3 py-2">
                  <span className="flex-1">{p.node_name ?? `node #${p.node_id}`} <span className="text-xs text-slate-500 dark:text-slate-400">{p.node_status} · {p.health}</span></span>
                  <Badge tone={p.status === 'ok' ? 'green' : 'red'}>{p.status === 'ok' ? 'good' : 'damaged'}</Badge>
                  {manage && p.status === 'ok' && <Button size="sm" variant="secondary" disabled={move.isPending} onClick={() => move.mutate(p.node_id)}>Move elsewhere</Button>}
                </li>
              ))}
              {!f.placements.length && <li className="px-3 py-2 text-slate-500">No copies — the file cannot be read.</li>}
            </ul>
          </div>
          {f.tasks.length > 0 && (
            <div>
              <h3 className="mb-2 font-medium text-slate-900 dark:text-white">Recent work</h3>
              <ul className="space-y-1">
                {f.tasks.map((t) => <li key={t.id} className="flex items-center gap-2"><Badge tone={TASK_TONE[t.status]}>{t.status}</Badge>{t.type} — {t.reason}{t.error && <span className="text-xs text-red-600 dark:text-red-400">{t.error}</span>}</li>)}
              </ul>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

export function PoliciesTab({ manage }: { manage: boolean }) {
  const toast = useToast();
  const q = useQuery({ queryKey: ['storage-policies'], queryFn: () => get<Policy[]>('/storage/policies') });
  const [editing, setEditing] = useState<Policy | 'new' | null>(null);
  const remove = useMutation({ mutationFn: (p: Policy) => del(`/storage/policies/${p.id}`), onSuccess: () => { toast.success('Policy deleted'); q.refetch(); }, onError: (e) => toast.error(e) });
  return (
    <Card>
      <CardHeader title="Placement policies" description="How many copies a file gets and how nodes are picked. An app uses its own policy if it has one, otherwise the default."
        actions={manage && <Button size="sm" icon={PlusIcon} onClick={() => setEditing('new')}>Add policy</Button>} />
      <ul className="divide-y divide-slate-200 dark:divide-white/10">
        {(q.data ?? []).map((p) => (
          <li key={p.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-slate-900 dark:text-white">{p.name} {Number(p.is_default) === 1 && <Badge tone="blue">default</Badge>} {p.app && <Badge>app: {p.app}</Badge>}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{p.replicas} {Number(p.replicas) === 1 ? 'copy' : 'copies'}{Number(p.min_regions ?? 1) > 1 ? ` in ${p.min_regions}+ regions` : ''} · {STRATEGY[p.strategy]}{p.node_tags ? ` · nodes tagged ${p.node_tags}` : ''} · {p.files} files</p>
            </div>
            {manage && (
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" icon={PencilSquareIcon} onClick={() => setEditing(p)}>Edit</Button>
                {Number(p.is_default) !== 1 && <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => remove.mutate(p)}>Delete</Button>}
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && <PolicyForm policy={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); q.refetch(); }} />}
    </Card>
  );
}

function PolicyForm({ policy, onClose, onSaved }: { policy: Policy | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [name, setName] = useState(policy?.name ?? '');
  const [replicas, setReplicas] = useState(String(policy?.replicas ?? 2));
  const [regions, setRegions] = useState(String(policy?.min_regions ?? 1));
  const [strategy, setStrategy] = useState(policy?.strategy ?? 'capacity');
  const [tags, setTags] = useState(policy?.node_tags ?? '');
  const [app, setApp] = useState(policy?.app ?? '');
  const [isDefault, setIsDefault] = useState(Number(policy?.is_default ?? 0) === 1);
  const save = useMutation({
    mutationFn: () => { const body = { name, replicas: Number(replicas), min_regions: Number(regions), strategy, node_tags: tags, app, is_default: isDefault }; return policy ? put(`/storage/policies/${policy.id}`, body) : post('/storage/policies', body); },
    onSuccess: () => { toast.success('Policy saved'); onSaved(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={policy ? 'Edit policy' : 'Add policy'} onSubmit={() => save.mutate()} submitting={save.isPending}>
      <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Copies" type="number" min={1} max={5} value={replicas} onChange={(e) => setReplicas(e.target.value)} hint="Capped by the number of active nodes" />
        <Select label="Choose nodes by" value={strategy} onChange={(e) => setStrategy(e.target.value)}
          options={Object.entries(STRATEGY).map(([value, label]) => ({ value, label }))} />
      </div>
      <Select label="Off-site copies" value={regions} onChange={(e) => setRegions(e.target.value)}
        hint="Disaster recovery: copies must sit in this many different regions (the Region / site of each node). Extra copies are added when needed."
        options={[{ value: '1', label: 'Any region' }, { value: '2', label: 'At least 2 regions' }, { value: '3', label: 'At least 3 regions' }]} />
      <Input label="Only nodes tagged" value={tags} onChange={(e) => setTags(e.target.value)} hint="Comma-separated; a node needs every tag. Leave empty for any node." />
      <Input label="Use for app" value={app} onChange={(e) => setApp(e.target.value)} hint="e.g. datasets or recordings. Leave empty for a general policy." />
      <Segmented label="Default" value={isDefault ? 'yes' : 'no'} onChange={(v) => setIsDefault(v === 'yes')} options={[{ value: 'no', label: 'Not default' }, { value: 'yes', label: 'Default policy' }]} />
    </FormModal>
  );
}

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

interface BackupRun { id: number; status: string; trigger: string; size: number | null; object_id: number | null; object_key: string | null; error: string | null; started_at: string; finished_at: string | null }
interface Backup { id: number; name: string; schedule: string; at_hour: number | string; keep: number | string; exclude_tables: string | null; policy_id: number | null; enabled: number | string; last_run_at: string | null; runs: BackupRun[] }
const RUN_TONE: Record<string, 'blue' | 'green' | 'red'> = { running: 'blue', ok: 'green', failed: 'red' };

export function BackupsTab({ manage }: { manage: boolean }) {
  const toast = useToast();
  const q = useQuery({ queryKey: ['storage-backups'], queryFn: () => get<Backup[]>('/storage/backups'), refetchInterval: 15_000 });
  const [editing, setEditing] = useState<Backup | null>(null);
  const run = useMutation({ mutationFn: (b: Backup) => post(`/storage/backups/${b.id}/run`), onSuccess: () => { toast.success('Backup started', 'It runs in the background.'); q.refetch(); }, onError: (e) => toast.error(e) });
  return (
    <div className="space-y-6">
      {(q.data ?? []).map((b) => (
        <Card key={b.id}>
          <CardHeader title={<span className="flex items-center gap-2">{b.name} <Badge tone={Number(b.enabled) === 1 ? 'green' : 'gray'}>{Number(b.enabled) === 1 ? 'on' : 'off'}</Badge></span>}
            description={`Database dump, ${b.schedule} at ${String(b.at_hour).padStart(2, '0')}:00 server time, keeps the newest ${b.keep}. Stored as files in app "backups" — give it a policy with copies in two regions for off-site recovery.`}
            actions={manage && (
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" icon={PencilSquareIcon} onClick={() => setEditing(b)}>Settings</Button>
                <Button size="sm" icon={ArrowPathIcon} disabled={run.isPending || b.runs[0]?.status === 'running'} onClick={() => run.mutate(b)}>Run now</Button>
              </div>
            )} />
          <ul className="divide-y divide-slate-200 dark:divide-white/10">
            {b.runs.map((r) => (
              <li key={r.id} className="flex flex-col gap-1 px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:gap-3 sm:px-6">
                <Badge tone={RUN_TONE[r.status] ?? 'gray'}>{r.status}</Badge>
                <span className="text-slate-700 dark:text-slate-300" title={dateTime(r.started_at)}>{ago(r.started_at)} · {r.trigger}</span>
                <span className="flex-1 truncate text-xs text-slate-500 dark:text-slate-400">{r.error ?? (r.object_key ? `${r.object_key}${r.size ? ` · ${bytes(r.size)}` : ''}` : r.object_id ? 'removed by the keep limit' : '')}</span>
                {r.status === 'ok' && r.object_key && r.object_id && <Button size="sm" variant="secondary" icon={ArrowDownTrayIcon} onClick={() => download(`/storage/files/${r.object_id}/download`, 'backup.sql.gz').catch((e) => toast.error(e))}>Download</Button>}
              </li>
            ))}
            {!b.runs.length && <li className="px-4 py-3 text-sm text-slate-500 sm:px-6 dark:text-slate-400">No runs yet.</li>}
          </ul>
        </Card>
      ))}
      {editing && <BackupForm backup={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); q.refetch(); }} />}
    </div>
  );
}

function BackupForm({ backup, onClose, onSaved }: { backup: Backup; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const pol = useQuery({ queryKey: ['storage-policies'], queryFn: () => get<Policy[]>('/storage/policies') });
  const [schedule, setSchedule] = useState(backup.schedule);
  const [hour, setHour] = useState(String(backup.at_hour));
  const [keep, setKeep] = useState(String(backup.keep));
  const [policy, setPolicy] = useState(String(backup.policy_id ?? ''));
  const [exclude, setExclude] = useState(backup.exclude_tables ?? '');
  const [enabled, setEnabled] = useState(Number(backup.enabled) === 1);
  const save = useMutation({
    mutationFn: () => put(`/storage/backups/${backup.id}`, { schedule, at_hour: Number(hour), keep: Number(keep), policy_id: policy ? Number(policy) : null, exclude_tables: exclude, enabled }),
    onSuccess: () => { toast.success('Backup saved'); onSaved(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={`${backup.name} — settings`} onSubmit={() => save.mutate()} submitting={save.isPending}>
      <Segmented label="Backups" value={enabled ? 'on' : 'off'} onChange={(v) => setEnabled(v === 'on')} options={[{ value: 'off', label: 'Off' }, { value: 'on', label: 'On' }]} />
      <div className="grid gap-4 sm:grid-cols-3">
        <Select label="Every" value={schedule} onChange={(e) => setSchedule(e.target.value)} options={[{ value: 'daily', label: 'Day' }, { value: 'weekly', label: 'Week' }]} />
        <Input label="At hour" type="number" min={0} max={23} value={hour} onChange={(e) => setHour(e.target.value)} hint="Server time (UTC)" />
        <Input label="Keep" type="number" min={1} max={365} value={keep} onChange={(e) => setKeep(e.target.value)} hint="Newest runs kept" />
      </div>
      <Select label="Placement policy" value={policy} onChange={(e) => setPolicy(e.target.value)}
        options={[{ value: '', label: 'The default policy' }, ...(pol.data ?? []).map((p) => ({ value: String(p.id), label: `${p.name} — ${p.replicas} copies${Number(p.min_regions ?? 1) > 1 ? `, ${p.min_regions}+ regions` : ''}` }))]} />
      <Input label="Leave out tables" value={exclude} onChange={(e) => setExclude(e.target.value)} hint="Comma-separated table names, e.g. large logs that can be rebuilt." />
    </FormModal>
  );
}
