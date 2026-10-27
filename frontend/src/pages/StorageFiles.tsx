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
export interface Policy { id: number; name: string; replicas: number | string; strategy: string; node_tags: string | null; app: string | null; is_default: number | string; files?: number | string }

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
              <p className="text-xs text-slate-500 dark:text-slate-400">{p.replicas} {Number(p.replicas) === 1 ? 'copy' : 'copies'} · {STRATEGY[p.strategy]}{p.node_tags ? ` · nodes tagged ${p.node_tags}` : ''} · {p.files} files</p>
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
  const [strategy, setStrategy] = useState(policy?.strategy ?? 'capacity');
  const [tags, setTags] = useState(policy?.node_tags ?? '');
  const [app, setApp] = useState(policy?.app ?? '');
  const [isDefault, setIsDefault] = useState(Number(policy?.is_default ?? 0) === 1);
  const save = useMutation({
    mutationFn: () => { const body = { name, replicas: Number(replicas), strategy, node_tags: tags, app, is_default: isDefault }; return policy ? put(`/storage/policies/${policy.id}`, body) : post('/storage/policies', body); },
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
