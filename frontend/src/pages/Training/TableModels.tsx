import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowUpTrayIcon, PlusIcon, TableCellsIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, CodeBlock, ConfirmDialog, EmptyState, FormModal, Input, Segmented, Select, useToast } from '../../components/ui';
import { del, get, post } from '../../lib/api';
import { ago, num } from '../../lib/format';

interface Feature { name: string; type: 'number' | 'category'; values?: string[]; min?: number; max?: number; median?: number }
interface Metrics {
  task: 'classification' | 'regression'; model: string; scoring: string; rows: number; train_rows: number; test_rows: number; features: number; seconds?: number;
  compare: { model: string; score: number | null; spread?: number; error?: string }[]; dropped: { column: string; why: string }[];
  importance: { column: string; value: number }[];
  accuracy?: number; macro_f1?: number; baseline?: number; labels?: string[]; confusion?: number[][] | null;
  r2?: number; mae?: number; rmse?: number; baseline_mae?: number;
}
interface TableModel {
  id: number; name: string; requested_name: string | null; kind: string; task: string | null; target: string | null; status: 'training' | 'ready' | 'failed' | 'replaced';
  progress: string | null; error: string | null; metrics: Metrics | null; schema: { features: Feature[]; example: Record<string, string | null> } | null; created_at: string;
}
interface Result { value: string | number; score?: number; scores?: { label: string; score: number }[] }

const pct = (v?: number) => (v == null ? '—' : `${(v * 100).toFixed(1)}%`);
const fmt = (v?: number) => (v == null ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 }));

/** Classic machine learning on a table: upload a CSV, pick the column to predict, get a served model. */
export default function TableModels({ canManage }: { canManage: boolean }) {
  const q = useQuery({
    queryKey: ['ml-models'], queryFn: () => get<TableModel[]>('/ml-models'),
    refetchInterval: (query) => ((query.state.data ?? []).some((m) => m.status === 'training') ? 3000 : 30_000),
  });
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const [removing, setRemoving] = useState<TableModel | null>(null);
  const qc = useQueryClient();
  const toast = useToast();
  const remove = useMutation({
    mutationFn: (m: TableModel) => del(`/ml-models/${m.id}`),
    onSuccess: () => { toast.success('Model deleted'); setRemoving(null); qc.invalidateQueries({ queryKey: ['ml-models'] }); }, onError: (e) => toast.error(e),
  });
  const models = (q.data ?? []).filter((m) => m.kind === 'table' && m.status !== 'replaced');
  if (!canManage && !models.length) return null;

  return (
    <Card className="mb-6">
      <CardHeader title="Table models" description="Predict a column of a spreadsheet — a category (will this customer renew?) or a number (next month's sales). Trained on this server in seconds to minutes; no GPU needed."
        actions={canManage && <Button size="sm" icon={PlusIcon} variant="primary" onClick={() => setCreating(true)}>New table model</Button>} />
      {!models.length ? (
        <EmptyState icon={TableCellsIcon} title="No table models yet" description="Upload a CSV with one row per example and a column holding the answer you want predicted." />
      ) : (
        <ul className="divide-y divide-slate-200 dark:divide-white/10">
          {models.map((m) => (
            <li key={m.id} className="px-4 py-4 sm:px-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <code className="font-medium text-slate-900 dark:text-white">{m.status === 'ready' ? m.name : m.requested_name}</code>
                    {m.status === 'training' && <Badge tone="blue">training</Badge>}
                    {m.status === 'failed' && <Badge tone="red">failed</Badge>}
                    {m.metrics && <Badge>{m.metrics.task === 'classification' ? `${m.metrics.labels?.length ?? 0} classes` : 'number'}</Badge>}
                    {m.metrics?.task === 'classification' && <Badge tone="blue">accuracy {pct(m.metrics.accuracy)}</Badge>}
                    {m.metrics?.task === 'classification' && <Badge tone="purple">macro-F1 {pct(m.metrics.macro_f1)}</Badge>}
                    {m.metrics?.task === 'regression' && <Badge tone="blue">R² {fmt(m.metrics.r2)}</Badge>}
                    {m.metrics?.task === 'regression' && <Badge tone="purple">typical error ±{fmt(m.metrics.mae)}</Badge>}
                  </div>
                  <p className="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">
                    predicts <strong>{m.target}</strong>
                    {m.metrics ? ` · ${m.metrics.model} · ${num(m.metrics.rows)} rows, ${m.metrics.features} input columns` : ''}
                    {m.status === 'training' ? ` · ${m.progress ?? 'starting'}` : ''} · {ago(m.created_at)}
                  </p>
                  {m.error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{m.error}</p>}
                </div>
                <div className="flex gap-2">
                  {m.status === 'ready' && <Button size="sm" variant={open === m.id ? 'primary' : 'secondary'} onClick={() => setOpen(open === m.id ? null : m.id)}>{open === m.id ? 'Close' : 'Details & try'}</Button>}
                  {canManage && m.status !== 'training' && <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => setRemoving(m)}>Delete</Button>}
                </div>
              </div>
              {open === m.id && m.metrics && m.schema && <Details model={m} metrics={m.metrics} />}
            </li>
          ))}
        </ul>
      )}
      {creating && <CreateForm onClose={() => setCreating(false)} onDone={() => { setCreating(false); qc.invalidateQueries({ queryKey: ['ml-models'] }); }} />}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => { if (removing) remove.mutate(removing); }}
        title={`Delete ${removing?.requested_name ?? removing?.name}?`} message="Apps calling it will get “No table model with that name”." confirmLabel="Delete" busy={remove.isPending} tone="danger" />
    </Card>
  );
}

/** Reads the header of the chosen CSV in the browser so the column to predict can be picked before uploading. */
function CreateForm({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [columns, setColumns] = useState<string[]>([]);
  const [name, setName] = useState('');
  const [target, setTarget] = useState('');
  const [task, setTask] = useState<'auto' | 'classification' | 'regression'>('auto');
  const pick = async (f: File) => {
    setFile(f);
    if (!name) setName(f.name.replace(/\.[^.]+$/, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''));
    const head = (await f.slice(0, 65536).text()).replace(/^﻿/, '').split(/\r?\n/)[0] ?? '';
    const sep = [',', ';', '\t', '|'].reduce((a, b) => (head.split(b).length > head.split(a).length ? b : a), ',');
    const cols = head.split(sep).map((c) => c.trim().replace(/^"|"$/g, '')).filter(Boolean);
    setColumns(cols); setTarget(cols[cols.length - 1] ?? '');
  };
  const save = useMutation({
    mutationFn: () => {
      const fd = new FormData();
      fd.append('file', file!); fd.append('name', name); fd.append('target', target); fd.append('task', task);
      return post('/ml-models/table', fd);
    },
    onSuccess: () => { toast.success('Training started', 'It appears ready on this page when done.'); onDone(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title="New table model" submitLabel="Train" submitting={save.isPending} onSubmit={() => { if (file && target) save.mutate(); }}
      description="One row per example, one column per fact, plus the column with the answer. Identifier and free-text columns are left out automatically. Up to 200 MB; 200,000 rows are used at most.">
      <input ref={input} type="file" accept=".csv,.tsv,.txt" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) pick(f); e.target.value = ''; }} />
      <div className="flex items-center gap-3">
        <Button icon={ArrowUpTrayIcon} variant="secondary" onClick={() => input.current?.click()}>{file ? 'Choose another file' : 'Choose CSV file'}</Button>
        {file && <span className="truncate text-sm text-slate-600 dark:text-slate-400">{file.name} · {(file.size / 1048576).toFixed(1)} MB · {columns.length} columns</span>}
      </div>
      {file && <>
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} hint="Apps call the model by this name. A new model with the same name replaces the old one." />
        <Select label="Column to predict" value={target} onChange={(e) => setTarget(e.target.value)} options={columns.map((c) => ({ value: c, label: c }))} />
        <Segmented label="Predict as" value={task} onChange={setTask} options={[{ value: 'auto', label: 'Decide for me' }, { value: 'classification', label: 'A category' }, { value: 'regression', label: 'A number' }]} />
      </>}
    </FormModal>
  );
}

function Details({ model, metrics: m }: { model: TableModel; metrics: Metrics }) {
  const toast = useToast();
  const feats = model.schema!.features;
  const [row, setRow] = useState<Record<string, string>>(() => Object.fromEntries(feats.map((f) => [f.name, String(model.schema!.example[f.name] ?? '')])));
  const predict = useMutation({ mutationFn: () => post<{ results: Result[] }>(`/ml-models/${model.id}/predict`, { row, top: 5 }), onError: (e) => toast.error(e) });
  const r = predict.data?.results[0];
  const maxImp = Math.max(...m.importance.map((i) => i.value), 1e-9);
  const origin = window.location.origin;
  return (
    <div className="mt-4 grid gap-6 lg:grid-cols-2">
      <div className="space-y-5 text-sm">
        <div>
          <h4 className="mb-2 font-medium text-slate-900 dark:text-white">Models compared ({m.scoring}, 5-fold cross-validation)</h4>
          <ul className="space-y-1">
            {m.compare.map((c) => (
              <li key={c.model} className="flex items-center gap-2">
                <span className="w-40">{c.model}</span>
                <span className="tabular-nums">{c.score == null ? <span className="text-red-600 dark:text-red-400">failed</span> : `${fmt(c.score)} ± ${fmt(c.spread)}`}</span>
                {c.model === m.model && <Badge tone="green">chosen</Badge>}
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
            On {num(m.test_rows)} rows it never saw: {m.task === 'classification'
              ? `${pct(m.accuracy)} right (always guessing the most common class would get ${pct(m.baseline)}).`
              : `off by ${fmt(m.mae)} on average (guessing the average would be off by ${fmt(m.baseline_mae)}); R² ${fmt(m.r2)}.`}
          </p>
        </div>
        <div>
          <h4 className="mb-2 font-medium text-slate-900 dark:text-white">Columns that matter most</h4>
          <div className="space-y-1.5">
            {m.importance.slice(0, 10).map((i) => (
              <div key={i.column} className="flex items-center gap-2 text-xs">
                <span className="w-36 truncate text-slate-600 dark:text-slate-400" title={i.column}>{i.column}</span>
                <span className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10"><span className="block h-full rounded-full bg-brand-600" style={{ width: `${Math.max(0, i.value / maxImp) * 100}%` }} /></span>
              </div>
            ))}
          </div>
        </div>
        {m.dropped.length > 0 && <p className="text-xs text-slate-500 dark:text-slate-400">Left out: {m.dropped.map((d) => `${d.column} (${d.why})`).join('; ')}.</p>}
      </div>
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          {feats.map((f) => f.type === 'category' && f.values?.length
            ? <Select key={f.name} label={f.name} value={row[f.name]} onChange={(e) => setRow({ ...row, [f.name]: e.target.value })}
                options={[...(f.values.includes(row[f.name]) || !row[f.name] ? [] : [row[f.name]]), ...f.values].map((v) => ({ value: v, label: v }))} />
            : <Input key={f.name} label={f.name} value={row[f.name]} onChange={(e) => setRow({ ...row, [f.name]: e.target.value })}
                hint={f.type === 'number' && f.min != null ? `${fmt(f.min)} – ${fmt(f.max)}` : undefined} />)}
        </div>
        <Button variant="primary" loading={predict.isPending} onClick={() => predict.mutate()}>Predict {model.target}</Button>
        {r && (
          <div className="rounded-lg p-3 ring-1 ring-slate-200 dark:ring-white/10">
            <p className="text-sm font-medium text-slate-900 dark:text-white">{model.target}: {typeof r.value === 'number' ? fmt(r.value) : r.value}{r.score != null && <span className="font-normal text-slate-500"> · {pct(r.score)}</span>}</p>
            {r.scores && r.scores.length > 1 && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{r.scores.map((s) => `${s.label} ${pct(s.score)}`).join(' · ')}</p>}
          </div>
        )}
        <CodeBlock className="whitespace-pre-wrap break-all">{`curl ${origin}/api/v1/predict \\
  -H "X-Api-Key: ohub_…" -H "X-Api-Secret: sk_…" \\
  -H "Content-Type: application/json" \\
  -d '${JSON.stringify({ model: model.name, row: Object.fromEntries(Object.entries(row).slice(0, 4)) })}'`}</CodeBlock>
      </div>
    </div>
  );
}
