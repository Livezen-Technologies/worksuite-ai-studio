import { useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowDownTrayIcon, ArrowLeftIcon, ArrowUpTrayIcon, DocumentDuplicateIcon, SparklesIcon, TrashIcon,
} from '@heroicons/react/24/outline';
import {
  Alert, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState, ErrorState, IconButton, PageHeader, Pagination,
  SearchInput, Skeleton, StatCard, Tabs, Toolbar, useToast, type Column,
} from '../../components/ui';
import { del, get, post } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { compact, dateTime, num } from '../../lib/format';
import { uploadWithProgress } from '../SinLama/upload';
import DatasetGovernance from '../SinLama/DatasetGovernance';
import DatasetSplits from '../SinLama/DatasetSplits';
import { RightsChip } from '../SinLama/engineDataset';
import { ACCEPT, exportDataset, langLabel, type Detail, type Item } from './shared';

export default function DatasetDetail({ id, onBack }: { id: number; onBack: () => void }) {
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
  const [tab, setTab] = useState<'rows' | 'governance' | 'splits'>('rows');
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
        meta={ds && <span className="text-sm text-slate-500 dark:text-slate-400">{langLabel(ds.language)} · created {dateTime(ds.created_at)} <RightsChip status={ds.rights_status} /></span>}
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

      <Tabs
        className="mb-6"
        value={tab}
        onChange={(k) => setTab(k as typeof tab)}
        tabs={[{ key: 'rows', label: 'Rows' }, { key: 'governance', label: 'Governance' }, { key: 'splits', label: 'Splits' }]}
      />
      {tab === 'governance' && ds && <DatasetGovernance key={ds.id} dataset={ds} />}
      {tab === 'splits' && ds && <DatasetSplits key={ds.id} dataset={ds} />}

      {tab === 'rows' && <>
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
      </>}

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
