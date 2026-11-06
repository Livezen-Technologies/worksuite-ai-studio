import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, DocumentIcon, FolderIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';
import { ActionMenu, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState, FormModal, Input, Modal, Select, useToast, type Column } from '../../components/ui';
import { del, download, get, post, put } from '../../lib/api';
import { ago, bytes, dateTime } from '../../lib/format';
import { STRATEGY, TASK_TONE, type FileDetail, type FileRow, type Listing } from './shared';

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
  const q = useQuery({ queryKey: ['storage-file', id], queryFn: () => get<FileDetail>(`/storage/files/${id}`),
    refetchInterval: (query) => (query.state.data?.tasks.some((t) => t.status === 'pending' || t.status === 'running') ? 10_000 : 60_000),
  });
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
