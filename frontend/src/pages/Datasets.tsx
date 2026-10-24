import { useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowDownTrayIcon, ArrowLeftIcon, ArrowUpTrayIcon, CircleStackIcon, DocumentDuplicateIcon, PlusIcon, SparklesIcon,
  TrashIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState, ErrorState, FormModal,
  IconButton, Input, PageHeader, Pagination, SearchInput, Select, Skeleton, StatCard, Textarea, Toolbar,
  useDisclosure, useToast, type Column,
} from '../components/ui';
import { ApiError, del, download, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, bytes, compact, dateTime, num } from '../lib/format';
import { uploadWithProgress } from './SinLama/upload';

interface Dataset {
  id: number; name: string; language: string; description: string | null;
  item_count: number | string; token_estimate: number | string; created_at: string; updated_at?: string;
}
interface Item { id: number; instruction: string; input: string | null; output: string; tokens: number | string }
interface Detail { dataset: Dataset; items: Item[]; total: number; page: number; per: number }

const LANGS = [
  { value: 'si', label: 'Sinhala' },
  { value: 'en', label: 'English' },
  { value: 'mixed', label: 'Mixed (si + en)' },
];
const langLabel = (l: string) => LANGS.find((x) => x.value === l)?.label ?? l;
const ACCEPT = '.json,.jsonl,.csv';

export default function Datasets() {
  const [openId, setOpenId] = useState<number | null>(null);
  return openId ? <DatasetDetail id={openId} onBack={() => setOpenId(null)} /> : <DatasetList onOpen={setOpenId} />;
}

/* ---------------------------------------------------------------- list */

function DatasetList({ onOpen }: { onOpen: (id: number) => void }) {
  const { can } = useAuth();
  const canManage = can('models.manage');
  const [q, setQ] = useState('');
  const create = useDisclosure();
  const [toDelete, setToDelete] = useState<Dataset | null>(null);
  const toast = useToast();
  const qc = useQueryClient();

  const list = useQuery({ queryKey: ['datasets'], queryFn: () => get<Dataset[]>('/datasets') });
  const rows = (list.data ?? []).filter((d) => !q || `${d.name} ${d.description ?? ''}`.toLowerCase().includes(q.toLowerCase()));
  const totals = (list.data ?? []).reduce((a, d) => ({ rows: a.rows + Number(d.item_count || 0), tokens: a.tokens + Number(d.token_estimate || 0) }), { rows: 0, tokens: 0 });

  const remove = useMutation({
    mutationFn: (id: number) => del(`/datasets/${id}`),
    onSuccess: () => { toast.success('Dataset deleted'); setToDelete(null); qc.invalidateQueries({ queryKey: ['datasets'] }); },
    onError: (e) => toast.error(e),
  });

  const columns: Column<Dataset>[] = [
    {
      key: 'name', header: 'Dataset', cell: (d) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-slate-900 dark:text-white">{d.name}</p>
          {d.description && <p className="truncate text-xs text-slate-500 dark:text-slate-400">{d.description}</p>}
        </div>
      ),
    },
    { key: 'language', header: 'Language', hideBelow: 'sm', cell: (d) => <Badge tone="indigo">{langLabel(d.language)}</Badge> },
    { key: 'rows', header: 'Rows', align: 'right', cell: (d) => <span className="tabular-nums">{num(d.item_count)}</span> },
    { key: 'tokens', header: 'Tokens (est.)', align: 'right', hideBelow: 'md', cell: (d) => <span className="tabular-nums">{compact(d.token_estimate)}</span> },
    { key: 'created', header: 'Created', hideBelow: 'lg', cell: (d) => <span title={dateTime(d.created_at)}>{ago(d.created_at)}</span> },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (d) => (
        <div onClick={(e) => e.stopPropagation()}>
          <ActionMenu actions={[
            { label: 'Open', onClick: () => onOpen(d.id) },
            { label: 'Export JSONL', icon: ArrowDownTrayIcon, onClick: () => exportDataset(d, toast) },
            { label: 'Delete', icon: TrashIcon, danger: true, hidden: !canManage, onClick: () => setToDelete(d) },
          ]} />
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Datasets"
        description="Alpaca-style instruction datasets for SinLama fine-tuning. Import JSON, JSONL or CSV, clean them, and export for training."
        actions={canManage && <Button icon={PlusIcon} onClick={create.onOpen}>New dataset</Button>}
      />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard name="Datasets" value={num(list.data?.length ?? 0)} icon={CircleStackIcon} loading={list.isLoading} />
        <StatCard name="Rows" value={num(totals.rows)} loading={list.isLoading} />
        <StatCard name="Tokens (est.)" value={compact(totals.tokens)} hint="~1 token per 3 bytes, Sinhala-aware" loading={list.isLoading} />
      </div>

      <Card>
        <Toolbar><SearchInput value={q} onChange={setQ} placeholder="Search datasets…" className="w-full sm:max-w-xs" /></Toolbar>
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(d) => d.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => list.refetch()}
          onRowClick={(d) => onOpen(d.id)}
          empty={
            <EmptyState
              icon={CircleStackIcon}
              title={q ? 'No datasets match your search' : 'No datasets yet'}
              description={q ? 'Try a different name.' : 'Create one and import instruction / input / output rows from a JSON, JSONL or CSV file.'}
              action={!q && canManage ? <Button icon={PlusIcon} onClick={create.onOpen}>New dataset</Button> : undefined}
            />
          }
        />
      </Card>

      <CreateDataset open={create.open} onClose={create.onClose} onCreated={onOpen} />
      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id)}
        busy={remove.isPending}
        title="Delete dataset?"
        message={<>“{toDelete?.name}” and all {num(toDelete?.item_count)} rows will be removed. Training jobs that use it will lose their dataset.</>}
      />
    </>
  );
}

function CreateDataset({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: number) => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [name, setName] = useState('');
  const [language, setLanguage] = useState('si');
  const [description, setDescription] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [progress, setProgress] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  function reset() { setName(''); setLanguage('si'); setDescription(''); setFile(null); setProgress(null); setErrors({}); }

  async function submit() {
    if (!name.trim()) { setErrors({ name: 'Dataset name is required.' }); return; }
    const form = new FormData();
    form.append('name', name.trim());
    form.append('language', language);
    form.append('description', description.trim());
    if (file) form.append('file', file);
    setBusy(true); setErrors({});
    try {
      const { data, message } = await uploadWithProgress<{ dataset: Dataset; imported: number; skipped: number }>('/datasets', form, setProgress);
      toast.success(message ?? 'Dataset created', data.skipped ? `${num(data.skipped)} rows skipped (duplicates or empty).` : undefined);
      qc.invalidateQueries({ queryKey: ['datasets'] });
      reset(); onClose();
      onCreated(data.dataset.id);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.fields);
        const created = (e.data as { dataset_id?: number } | undefined)?.dataset_id;
        if (created) { qc.invalidateQueries({ queryKey: ['datasets'] }); reset(); onClose(); onCreated(created); }
      }
      toast.error(e);
    } finally {
      setBusy(false); setProgress(null);
    }
  }

  return (
    <FormModal open={open} onClose={() => { if (!busy) { reset(); onClose(); } }} title="New dataset" submitLabel="Create dataset" submitting={busy} onSubmit={submit}>
      <div className="space-y-4">
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="sinhala-support-v1" error={errors.name} autoFocus />
        <Select label="Language" value={language} onChange={(e) => setLanguage(e.target.value)} options={LANGS} />
        <Textarea label="Description" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        <FileField file={file} onFile={setFile} progress={progress} />
      </div>
    </FormModal>
  );
}

function FileField({ file, onFile, progress }: { file: File | null; onFile: (f: File | null) => void; progress: number | null }) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <div>
      <p className="text-sm/6 font-medium text-slate-900 dark:text-white">Import file (optional)</p>
      <div className="mt-1.5 flex items-center gap-3 rounded-lg border border-dashed border-slate-300 p-3 dark:border-white/15">
        <input ref={ref} type="file" accept={ACCEPT} hidden onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
        <Button type="button" variant="secondary" size="sm" icon={ArrowUpTrayIcon} onClick={() => ref.current?.click()}>Choose file</Button>
        <span className="min-w-0 truncate text-sm text-slate-600 dark:text-slate-400">
          {file ? `${file.name} · ${bytes(file.size)}` : 'JSON, JSONL or CSV'}
        </span>
        {file && progress === null && <IconButton label="Remove file" icon={TrashIcon} variant="ghost" size="sm" onClick={() => onFile(null)} />}
      </div>
      {progress !== null && <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{progress >= 1 ? 'Importing rows…' : `Uploading ${Math.round(progress * 100)}%`}</p>}
      <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
        Fields <code>instruction</code>, <code>input</code>, <code>output</code> (aliases question/answer and prompt/response work). Exact duplicates are skipped.
      </p>
    </div>
  );
}

async function exportDataset(d: Dataset, toast: ReturnType<typeof useToast>) {
  try {
    await download(`/datasets/${d.id}/export`, `${d.name.replace(/[^a-z0-9_-]+/gi, '-') || 'dataset'}.jsonl`);
    toast.success('Export started', 'Alpaca-format JSONL.');
  } catch (e) { toast.error(e); }
}

/* -------------------------------------------------------------- detail */

function DatasetDetail({ id, onBack }: { id: number; onBack: () => void }) {
  const { can } = useAuth();
  const canManage = can('models.manage');
  const toast = useToast();
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [confirm, setConfirm] = useState<'dataset' | 'dedupe' | 'clean' | null>(null);
  const [rowToDelete, setRowToDelete] = useState<Item | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [uploadPct, setUploadPct] = useState<number | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const detail = useQuery({
    queryKey: ['datasets', id, { page, q }],
    queryFn: () => get<Detail>(`/datasets/${id}`, { page, q }),
    placeholderData: keepPreviousData,
  });
  const ds = detail.data?.dataset;
  const refresh = () => qc.invalidateQueries({ queryKey: ['datasets'] });

  const action = useMutation({
    mutationFn: (kind: 'dedupe' | 'clean') => post<{ removed: number }>(`/datasets/${id}/${kind}`),
    onSuccess: (r, kind) => {
      toast.success(kind === 'dedupe' ? `${num(r.removed)} duplicate rows removed` : `Clean complete — ${num(r.removed)} empty rows removed`);
      setConfirm(null); refresh();
    },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: () => del(`/datasets/${id}`),
    onSuccess: () => { toast.success('Dataset deleted'); refresh(); onBack(); },
    onError: (e) => toast.error(e),
  });
  const removeRow = useMutation({
    mutationFn: (itemId: number) => del(`/datasets/items/${itemId}`),
    onSuccess: () => { toast.success('Row deleted'); setRowToDelete(null); refresh(); },
    onError: (e) => toast.error(e),
  });

  async function upload(file: File) {
    const form = new FormData();
    form.append('file', file);
    setUploadPct(0);
    try {
      const { message } = await uploadWithProgress(`/datasets/${id}/upload`, form, setUploadPct);
      toast.success(message ?? 'Rows imported');
      refresh();
    } catch (e) { toast.error(e); } finally { setUploadPct(null); }
  }

  const columns: Column<Item>[] = [
    { key: 'id', header: '#', hideBelow: 'md', className: 'w-16', cell: (r) => <span className="tabular-nums text-slate-500 dark:text-slate-400">{r.id}</span> },
    {
      key: 'instruction', header: 'Instruction', cell: (r) => (
        <div className="max-w-md">
          <p className={expanded === r.id ? 'whitespace-pre-wrap' : 'line-clamp-2'}>{r.instruction}</p>
          {r.input && <p className={`mt-1 text-xs text-slate-500 dark:text-slate-400 ${expanded === r.id ? 'whitespace-pre-wrap' : 'line-clamp-1'}`}>Input: {r.input}</p>}
        </div>
      ),
    },
    { key: 'output', header: 'Output', hideBelow: 'sm', cell: (r) => <p className={`max-w-md ${expanded === r.id ? 'whitespace-pre-wrap' : 'line-clamp-2'}`}>{r.output}</p> },
    { key: 'tokens', header: 'Tokens', align: 'right', hideBelow: 'lg', cell: (r) => <span className="tabular-nums">{num(r.tokens)}</span> },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (r) =>
        canManage ? (
          <span onClick={(e) => e.stopPropagation()}>
            <IconButton label="Delete row" icon={TrashIcon} variant="ghost" size="sm" onClick={() => setRowToDelete(r)} />
          </span>
        ) : null,
    },
  ];

  if (detail.isError && !detail.data) {
    return (
      <>
        <PageHeader title="Dataset" actions={<Button variant="secondary" icon={ArrowLeftIcon} onClick={onBack}>All datasets</Button>} />
        <ErrorState error={detail.error} onRetry={() => detail.refetch()} />
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={ds ? ds.name : <Skeleton className="h-8 w-48" />}
        description={ds?.description ?? undefined}
        meta={ds && <span className="text-sm text-slate-500 dark:text-slate-400">{langLabel(ds.language)} · created {dateTime(ds.created_at)}</span>}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" icon={ArrowLeftIcon} onClick={onBack}>All datasets</Button>
            {ds && <Button variant="secondary" icon={ArrowDownTrayIcon} onClick={() => exportDataset(ds, toast)}>Export JSONL</Button>}
            {canManage && <Button icon={ArrowUpTrayIcon} loading={uploadPct !== null} onClick={() => fileRef.current?.click()}>
              {uploadPct === null ? 'Upload rows' : uploadPct >= 1 ? 'Importing…' : `Uploading ${Math.round(uploadPct * 100)}%`}
            </Button>}
          </div>
        }
      />
      <input ref={fileRef} type="file" accept={ACCEPT} hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f); e.target.value = ''; }} />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard name="Rows" value={num(ds?.item_count)} loading={!ds} />
        <StatCard name="Tokens (est.)" value={compact(ds?.token_estimate)} hint="~1 token per 3 bytes" loading={!ds} />
        <StatCard name="Avg tokens / row" value={ds && Number(ds.item_count) ? num(Math.round(Number(ds.token_estimate) / Number(ds.item_count))) : '—'} loading={!ds} />
      </div>

      {canManage && (
        <Card className="mb-6">
          <CardHeader title="Tidy up" description="Run these before generating a training kit." />
          <div className="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-sm font-medium text-slate-900 dark:text-white">Remove duplicates</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">Deletes rows whose instruction, input and output exactly match another row.</p>
              </div>
              <Button variant="secondary" size="sm" icon={DocumentDuplicateIcon} onClick={() => setConfirm('dedupe')}>Dedupe</Button>
            </div>
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-sm font-medium text-slate-900 dark:text-white">Clean</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">Normalises whitespace and drops rows with an empty instruction or output.</p>
              </div>
              <Button variant="secondary" size="sm" icon={SparklesIcon} onClick={() => setConfirm('clean')}>Clean</Button>
            </div>
          </div>
        </Card>
      )}

      <Card>
        <Toolbar right={detail.data && <span className="text-sm text-slate-500 dark:text-slate-400">{num(detail.data.total)} {q ? 'matching' : ''} rows</span>}>
          <SearchInput value={q} onChange={(v) => { setQ(v); setPage(1); }} placeholder="Search instructions and outputs…" className="w-full sm:max-w-sm" />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={detail.data?.items}
          rowKey={(r) => r.id}
          loading={detail.isLoading}
          error={detail.error}
          onRetry={() => detail.refetch()}
          onRowClick={(r) => setExpanded((x) => (x === r.id ? null : r.id))}
          empty={
            <EmptyState
              title={q ? 'No rows match' : 'This dataset is empty'}
              description={q ? 'Search looks in the instruction and output text.' : 'Upload a JSON, JSONL or CSV file to add rows.'}
              action={!q && canManage ? <Button icon={ArrowUpTrayIcon} onClick={() => fileRef.current?.click()}>Upload rows</Button> : undefined}
            />
          }
        />
        {detail.data && <Pagination page={detail.data.page} perPage={detail.data.per} total={detail.data.total} onPage={setPage} />}
      </Card>

      {canManage && (
        <Card className="mt-6" padded>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-sm font-medium text-slate-900 dark:text-white">Delete this dataset</p>
              <p className="text-sm text-slate-500 dark:text-slate-400">Removes the dataset and every row. This cannot be undone.</p>
            </div>
            <Button variant="danger" icon={TrashIcon} onClick={() => setConfirm('dataset')}>Delete dataset</Button>
          </div>
        </Card>
      )}

      <ConfirmDialog
        open={confirm === 'dedupe' || confirm === 'clean'}
        onClose={() => setConfirm(null)}
        onConfirm={() => (confirm === 'dedupe' || confirm === 'clean') && action.mutate(confirm)}
        busy={action.isPending}
        tone="primary"
        confirmLabel={confirm === 'dedupe' ? 'Remove duplicates' : 'Clean dataset'}
        title={confirm === 'dedupe' ? 'Remove duplicate rows?' : 'Clean this dataset?'}
        message={confirm === 'dedupe' ? 'Exact duplicate rows will be deleted; one copy of each is kept.' : 'Whitespace is normalised and empty rows are deleted.'}
      />
      <ConfirmDialog
        open={confirm === 'dataset'}
        onClose={() => setConfirm(null)}
        onConfirm={() => remove.mutate()}
        busy={remove.isPending}
        title="Delete dataset?"
        message={<>“{ds?.name}” and all {num(ds?.item_count)} rows will be removed.</>}
      />
      <ConfirmDialog
        open={rowToDelete !== null}
        onClose={() => setRowToDelete(null)}
        onConfirm={() => rowToDelete && removeRow.mutate(rowToDelete.id)}
        busy={removeRow.isPending}
        title="Delete this row?"
        message={<Alert tone="warning">{rowToDelete?.instruction.slice(0, 160)}</Alert>}
      />
    </>
  );
}
