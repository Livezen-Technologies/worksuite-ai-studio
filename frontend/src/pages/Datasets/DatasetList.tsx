import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownTrayIcon, CircleStackIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState, PageHeader, SearchInput, Segmented, Select,
  StatCard, Toolbar, useDisclosure, useToast, type Column,
} from '../../components/ui';
import { del, get } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ago, compact, dateTime, num } from '../../lib/format';
import { RightsChip } from '../SinLama/engineDataset';
import CreateDataset from './CreateDataset';
import { CATEGORIES, category, exportDataset, langLabel, type Dataset } from './shared';

export default function DatasetList({ onOpen }: { onOpen: (id: number) => void }) {
  const { can } = useAuth();
  const canManage = can('models.manage');
  const [q, setQ] = useState('');
  const [rights, setRights] = useState<'all' | 'approved' | 'quarantined' | 'withdrawn'>('all');
  const [cat, setCat] = useState('');
  const create = useDisclosure();
  const [toDelete, setToDelete] = useState<Dataset | null>(null);
  const toast = useToast();
  const qc = useQueryClient();

  const list = useQuery({ queryKey: ['datasets'], queryFn: () => get<Dataset[]>('/datasets') });
  const rows = (list.data ?? [])
    .filter((d) => rights === 'all' || (d.rights_status ?? 'quarantined') === rights)
    .filter((d) => !cat || d.category === cat)
    .filter((d) => !q || `${d.id} ${d.name} ${d.description ?? ''}`.toLowerCase().includes(q.toLowerCase()))
    .sort((a, b) => Number(b.id) - Number(a.id));
  const count = (r: string) => (list.data ?? []).filter((d) => (d.rights_status ?? 'quarantined') === r).length;
  const totals = (list.data ?? []).reduce((a, d) => ({ rows: a.rows + Number(d.item_count || 0), tokens: a.tokens + Number(d.token_estimate || 0) }), { rows: 0, tokens: 0 });

  const remove = useMutation({
    mutationFn: (id: number) => del(`/datasets/${id}`),
    onSuccess: () => { toast.success('Dataset deleted'); setToDelete(null); qc.invalidateQueries({ queryKey: ['datasets'] }); },
    onError: (e) => toast.error(e),
  });

  const columns: Column<Dataset>[] = [
    { key: 'id', header: '#', hideBelow: 'md', className: 'w-12', cell: (d) => <span className="tabular-nums text-slate-500 dark:text-slate-400">{d.id}</span> },
    {
      key: 'name', header: 'Dataset', cell: (d) => (
        <div className="max-w-[16rem] min-w-0 sm:max-w-sm lg:max-w-md xl:max-w-xl" title={d.description ? `${d.name}\n${d.description}` : d.name}>
          <p className="truncate font-medium text-slate-900 dark:text-white">{d.name}</p>
          {d.description && <p className="truncate text-xs font-normal text-slate-500 dark:text-slate-400">{d.description}</p>}
        </div>
      ),
    },
    { key: 'category', header: 'Category', className: 'whitespace-nowrap', cell: (d) => { const c = category(d.category); return c ? <Badge tone={c.tone}>{c.label}</Badge> : <span className="text-slate-400">—</span>; } },
    { key: 'rights', header: 'Rights', className: 'whitespace-nowrap', cell: (d) => <RightsChip status={d.rights_status} /> },
    { key: 'language', header: 'Language', hideBelow: 'lg', className: 'whitespace-nowrap', cell: (d) => <Badge tone="indigo">{langLabel(d.language)}</Badge> },
    { key: 'rows', header: 'Rows', align: 'right', className: 'whitespace-nowrap', cell: (d) => <span className="tabular-nums">{num(d.item_count)}</span> },
    { key: 'tokens', header: 'Tokens', align: 'right', hideBelow: 'md', className: 'whitespace-nowrap', cell: (d) => <span className="tabular-nums">{compact(d.token_estimate)}</span> },
    { key: 'created', header: 'Created', hideBelow: 'lg', className: 'whitespace-nowrap', cell: (d) => <span title={dateTime(d.created_at)}>{ago(d.created_at)}</span> },
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
        description="Instruction datasets for Sinhala fine-tuning, each with its source and rights. Only approved datasets can be frozen and used for training."
        actions={canManage && <Button icon={PlusIcon} onClick={create.onOpen}>New dataset</Button>}
      />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard name="Datasets" value={num(list.data?.length ?? 0)} icon={CircleStackIcon} loading={list.isLoading} />
        <StatCard name="Rows" value={num(totals.rows)} loading={list.isLoading} />
        <StatCard name="Tokens (est.)" value={compact(totals.tokens)} hint="~1 token per 3 bytes, Sinhala-aware" loading={list.isLoading} />
      </div>

      <Card>
        <Toolbar
          right={
            <Segmented
              label="Rights"
              value={rights}
              onChange={setRights}
              options={[
                { value: 'all', label: `All ${list.data?.length ?? ''}`.trim() },
                { value: 'approved', label: `Approved ${count('approved')}` },
                { value: 'quarantined', label: `Quarantined ${count('quarantined')}` },
                ...(count('withdrawn') ? [{ value: 'withdrawn' as const, label: `Withdrawn ${count('withdrawn')}` }] : []),
              ]}
            />
          }
        >
          <div className="flex w-full flex-col gap-3 sm:flex-row">
            <SearchInput value={q} onChange={setQ} placeholder="Search by name, description or #…" className="w-full sm:max-w-xs" />
            <Select
              aria-label="Category"
              value={cat}
              onChange={(e) => setCat(e.target.value)}
              options={[{ value: '', label: 'All categories' }, ...CATEGORIES.map((c) => ({ value: c.value, label: `${c.label} ${(list.data ?? []).filter((d) => d.category === c.value).length}` }))]}
              wrapperClassName="sm:w-52"
            />
          </div>
        </Toolbar>
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
