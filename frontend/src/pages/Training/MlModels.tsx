import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { TagIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, CodeBlock, ConfirmDialog, EmptyState, Textarea, useToast } from '../../components/ui';
import { del, get, post } from '../../lib/api';
import { ago, num } from '../../lib/format';

interface MlModel {
  id: number; name: string; kind: string; job_id: number | null; dataset_name: string | null; base_model: string | null; status: string;
  labels: string[]; metrics: { accuracy?: number; macro_f1?: number; train_rows?: number; dev_rows?: number; epoch?: number } | null; created_at: string;
}
interface Prediction { label: string; score: number; scores: { label: string; score: number }[] }

const pct = (v?: number) => (v == null ? '—' : `${(v * 100).toFixed(1)}%`);

/** Trained text classifiers: quality, a Try box, and how to call them from an app. */
export default function MlModels({ canManage }: { canManage: boolean }) {
  const q = useQuery({ queryKey: ['ml-models'], queryFn: () => get<MlModel[]>('/ml-models'), refetchInterval: 30_000 });
  const [open, setOpen] = useState<number | null>(null);
  const [removing, setRemoving] = useState<MlModel | null>(null);
  const qc = useQueryClient();
  const toast = useToast();
  const remove = useMutation({
    mutationFn: (m: MlModel) => del(`/ml-models/${m.id}`),
    onSuccess: () => { toast.success('Classifier deleted'); setRemoving(null); qc.invalidateQueries({ queryKey: ['ml-models'] }); },
    onError: (e) => toast.error(e),
  });
  const models = (q.data ?? []).filter((m) => m.status !== 'replaced' && m.kind !== 'table');   // table models have their own card
  if (!q.isLoading && !models.length) return null;   // appears once the first classifier is trained

  return (
    <Card className="mb-6">
      <CardHeader title="Text classifiers" description="Trained here and served by this server. Try one, or call it from an app with an API key that has the Classify text scope." />
      {!models.length ? <EmptyState icon={TagIcon} title="Loading…" /> : (
        <ul className="divide-y divide-slate-200 dark:divide-white/10">
          {models.map((m) => (
            <li key={m.id} className="px-4 py-4 sm:px-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <code className="font-medium text-slate-900 dark:text-white">{m.name}</code>
                    <Badge tone="green">{m.labels.length} labels</Badge>
                    {m.metrics?.accuracy != null && <Badge tone="blue">accuracy {pct(m.metrics.accuracy)}</Badge>}
                    {m.metrics?.macro_f1 != null && <Badge tone="purple">macro-F1 {pct(m.metrics.macro_f1)}</Badge>}
                  </div>
                  <p className="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">
                    {m.base_model} · {m.dataset_name ?? 'dataset'}{m.metrics?.train_rows ? ` · ${num(m.metrics.train_rows)} training rows` : ''} · job #{m.job_id} · {ago(m.created_at)}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button size="sm" variant={open === m.id ? 'primary' : 'secondary'} onClick={() => setOpen(open === m.id ? null : m.id)}>{open === m.id ? 'Close' : 'Try it'}</Button>
                  {canManage && <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => setRemoving(m)}>Delete</Button>}
                </div>
              </div>
              {open === m.id && <TryBox model={m} />}
            </li>
          ))}
        </ul>
      )}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => { if (removing) remove.mutate(removing); }}
        title={`Delete ${removing?.name}?`} message="Apps calling it will get “No classifier with that name”. Its training job stays on this page." confirmLabel="Delete" busy={remove.isPending} tone="danger" />
    </Card>
  );
}

function TryBox({ model }: { model: MlModel }) {
  const [text, setText] = useState('');
  const toast = useToast();
  const predict = useMutation({
    mutationFn: () => post<{ results: Prediction[] }>(`/ml-models/${model.id}/predict`, { texts: text.split(/\n{2,}/).map((t) => t.trim()).filter(Boolean), top: 5 }),
    onError: (e) => toast.error(e),
  });
  const origin = window.location.origin;
  return (
    <div className="mt-4 grid gap-4 lg:grid-cols-2">
      <div>
        <Textarea label="Text" rows={5} value={text} onChange={(e) => setText(e.target.value)} placeholder="Paste a text to classify. Separate several with a blank line." />
        <Button className="mt-3" variant="primary" loading={predict.isPending} disabled={!text.trim()} onClick={() => predict.mutate()}>Classify</Button>
        <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Labels: {model.labels.slice(0, 12).join(', ')}{model.labels.length > 12 ? ', …' : ''}</p>
      </div>
      <div className="space-y-4">
        {predict.data?.results.map((r, i) => (
          <div key={i} className="rounded-lg p-3 ring-1 ring-slate-200 dark:ring-white/10">
            <p className="text-sm font-medium text-slate-900 dark:text-white">{r.label} <span className="font-normal text-slate-500">· {pct(r.score)}</span></p>
            <div className="mt-2 space-y-1.5">
              {r.scores.map((s) => (
                <div key={s.label} className="flex items-center gap-2 text-xs">
                  <span className="w-28 truncate text-slate-600 dark:text-slate-400" title={s.label}>{s.label}</span>
                  <span className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10"><span className="block h-full rounded-full bg-brand-600" style={{ width: `${s.score * 100}%` }} /></span>
                  <span className="w-12 text-right tabular-nums text-slate-500">{pct(s.score)}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
        {!predict.data && (
          <CodeBlock className="whitespace-pre-wrap break-all">{`curl ${origin}/api/v1/classify \\
  -H "X-Api-Key: ohub_…" -H "X-Api-Secret: sk_…" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"${model.name}","text":"…"}'`}</CodeBlock>
        )}
      </div>
    </div>
  );
}
