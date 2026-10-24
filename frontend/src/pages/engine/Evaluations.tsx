import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BeakerIcon, CheckBadgeIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  Alert, Button, Card, CardHeader, ConfirmDialog, DataTable, DescriptionList, EmptyState, ErrorState, FormModal, IconButton,
  Input, PageHeader, SearchInput, Select, Skeleton, Textarea, Toolbar, useToast, type Column,
} from '../../components/ui';
import { get, post } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ago, dateTime } from '../../lib/format';
import {
  ApprovalMark, BackLink, CheckBadge, DecisionBadge, Digest, StateBadge, fmtMetric, refusalText, taskLabel, taskOptions,
  useEvaluations, useOverview, type Evaluation,
} from './shared';

export default function EngineEvaluations() {
  const id = useParams()['*'];
  return id ? <EvaluationDetail id={id} /> : <EvaluationList />;
}

/* ---------------------------------------------------------------- list */

function EvaluationList() {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const nav = useNavigate();
  const [params, setParams] = useSearchParams();
  const creating = params.get('new') === '1';
  const [q, setQ] = useState('');
  const [task, setTask] = useState('');
  const list = useEvaluations();
  const ov = useOverview();

  const rows = (list.data ?? []).filter((e) => (!task || e.task === task)
    && (!q || `${e.candidate_digest} ${e.suite_digest} ${e.uuid} ${e.notes ?? ''}`.toLowerCase().includes(q.toLowerCase())));

  const openNew = () => setParams((p) => { p.set('new', '1'); return p; });
  const closeNew = () => setParams((p) => { p.delete('new'); p.delete('training_job'); return p; });

  const columns: Column<Evaluation>[] = [
    { key: 'task', header: 'Task', cell: (e) => taskLabel(e.task) },
    { key: 'cand', header: 'Candidate', cell: (e) => <Digest value={e.candidate_digest} copy={false} /> },
    { key: 'suite', header: 'Suite', hideBelow: 'lg', cell: (e) => <Digest value={e.suite_digest} copy={false} /> },
    { key: 'dec', header: 'Decision', cell: (e) => <DecisionBadge decision={e.decision} /> },
    { key: 'appr', header: 'Approvals', hideBelow: 'md', cell: (e) => (
      <div className="flex flex-wrap gap-1">
        <ApprovalMark label="Release" given={!!e.release_approver} />
        <ApprovalMark label="Language" given={!!e.language_approver} />
      </div>
    ) },
    { key: 'at', header: 'Created', hideBelow: 'sm', cell: (e) => <span title={dateTime(e.created_at)}>{ago(e.created_at)}</span> },
  ];

  return (
    <>
      <BackLink to="/engine">Engine overview</BackLink>
      <PageHeader
        title="Evaluations"
        description="Gate results for candidate packages on frozen test suites."
        actions={canManage ? <Button variant="primary" icon={PlusIcon} onClick={openNew}>New evaluation</Button> : undefined}
      />
      <Card>
        <Toolbar right={<Select aria-label="Task" value={task} onChange={(e) => setTask(e.target.value)} placeholder="All tasks" options={taskOptions(Object.keys(ov.data?.gates ?? {}))} />}>
          <SearchInput value={q} onChange={setQ} placeholder="Search digest or notes…" />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={list.data ? rows : undefined}
          rowKey={(e) => e.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => list.refetch()}
          onRowClick={(e) => nav(`/engine/evaluations/${e.id}`)}
          empty={<EmptyState icon={BeakerIcon} title={list.data?.length ? 'No evaluations match' : 'No evaluations yet'}
            description="Record an evaluation to check a candidate against its release gate."
            action={canManage ? <Button variant="primary" icon={PlusIcon} onClick={openNew}>New evaluation</Button> : undefined} />}
        />
      </Card>
      {canManage && <EvaluationForm open={creating} onClose={closeNew} initialJob={params.get('training_job') ?? ''} onCreated={(e) => nav(`/engine/evaluations/${e.id}`)} />}
    </>
  );
}

/* ---------------------------------------------------------------- create */

interface KV { k: string; v: string }
interface SG { name: string; n: string; metrics: string; baseline: string }
const HEX = /^[a-f0-9]{64}$/;

/** "a=0.1, b=0.2" → {a:0.1,b:0.2} */
function parsePairs(s: string): Record<string, number> {
  const out: Record<string, number> = {};
  for (const part of s.split(/[,\n]/)) {
    const [k, v] = part.split('=').map((x) => x?.trim());
    if (k && v !== undefined && v !== '' && !Number.isNaN(Number(v))) out[k] = Number(v);
  }
  return out;
}
const kvToObj = (rows: KV[]) => Object.fromEntries(rows.filter((r) => r.k.trim() && r.v.trim() !== '' && !Number.isNaN(Number(r.v))).map((r) => [r.k.trim(), Number(r.v)]));

function KVEditor({ label, rows, onChange, hint }: { label: string; rows: KV[]; onChange: (r: KV[]) => void; hint?: string }) {
  return (
    <fieldset>
      <legend className="text-sm/6 font-medium text-slate-900 dark:text-white">{label}</legend>
      {hint && <p className="text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
      <div className="mt-2 space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="flex items-center gap-2">
            <Input aria-label="Metric" placeholder="metric" value={r.k} wrapperClassName="flex-1" onChange={(e) => onChange(rows.map((x, j) => (j === i ? { ...x, k: e.target.value } : x)))} />
            <Input aria-label={`Value for ${r.k || 'metric'}`} placeholder="value" inputMode="decimal" value={r.v} wrapperClassName="w-28" onChange={(e) => onChange(rows.map((x, j) => (j === i ? { ...x, v: e.target.value } : x)))} />
            <IconButton label="Remove metric" icon={TrashIcon} variant="ghost" size="sm" onClick={() => onChange(rows.filter((_, j) => j !== i))} />
          </div>
        ))}
        <Button size="sm" variant="ghost" icon={PlusIcon} onClick={() => onChange([...rows, { k: '', v: '' }])}>Add metric</Button>
      </div>
    </fieldset>
  );
}

function EvaluationForm({ open, onClose, initialJob, onCreated }: { open: boolean; onClose: () => void; initialJob: string; onCreated: (e: Evaluation) => void }) {
  const ov = useOverview();
  const toast = useToast();
  const qc = useQueryClient();
  const jobs = useQuery({ queryKey: ['training'], queryFn: () => get<{ jobs: { id: number; name: string }[] }>('/training'), enabled: open });
  const tasks = Object.keys(ov.data?.gates ?? {});
  const [f, setF] = useState({ task: '', training_job_id: initialJob, candidate_digest: '', baseline_digest: '', suite_digest: '', hardware: '', runtime_lock: '', notes: '' });
  const [metrics, setMetrics] = useState<KV[]>([]);
  const [baseline, setBaseline] = useState<KV[]>([]);
  const [subs, setSubs] = useState<SG[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => { if (open) setF((x) => ({ ...x, training_job_id: initialJob || x.training_job_id })); }, [open, initialJob]);

  const setTask = (task: string) => {
    setF((x) => ({ ...x, task }));
    const keys = Object.keys(ov.data?.gates?.[task] ?? {});
    setMetrics(keys.map((k) => ({ k, v: '' })));
    setBaseline([]);
  };

  const save = useMutation({
    mutationFn: () => post<Evaluation>('/engine/evaluations', {
      task: f.task,
      training_job_id: f.training_job_id ? Number(f.training_job_id) : null,
      candidate_digest: f.candidate_digest.trim().toLowerCase(),
      baseline_digest: f.baseline_digest.trim().toLowerCase() || null,
      suite_digest: f.suite_digest.trim().toLowerCase(),
      hardware: f.hardware || null,
      runtime_lock: f.runtime_lock || null,
      metrics: kvToObj(metrics),
      baseline_metrics: baseline.length ? kvToObj(baseline) : null,
      subgroups: subs.filter((s) => s.name.trim()).map((s) => ({ name: s.name.trim(), n: Number(s.n || 0), metrics: parsePairs(s.metrics), baseline: parsePairs(s.baseline) })),
      notes: f.notes || null,
    }),
    onSuccess: (e) => {
      toast.success(e.decision === 'pass' ? 'Evaluation passed its release gate' : 'Evaluation recorded — gate not passed');
      void qc.invalidateQueries({ queryKey: ['engine'] });
      onClose();
      onCreated(e);
    },
    onError: (e) => toast.error(refusalText(e)),
  });

  const submit = () => {
    const errs: Record<string, string> = {};
    if (!f.task) errs.task = 'Choose a task.';
    if (!HEX.test(f.candidate_digest.trim().toLowerCase())) errs.candidate_digest = 'A 64-character sha256 hex digest.';
    if (!HEX.test(f.suite_digest.trim().toLowerCase())) errs.suite_digest = 'A 64-character sha256 hex digest.';
    if (f.baseline_digest.trim() && !HEX.test(f.baseline_digest.trim().toLowerCase())) errs.baseline_digest = 'A 64-character sha256 hex digest, or leave empty.';
    setErrors(errs);
    if (!Object.keys(errs).length) save.mutate();
  };

  return (
    <FormModal open={open} onClose={onClose} title="New evaluation" description="Metrics are checked against the task's release gate when saved; a missing metric counts as insufficient evidence." onSubmit={submit} submitLabel="Record evaluation" submitting={save.isPending} size="xl">
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label="Task" required value={f.task} error={errors.task} onChange={(e) => setTask(e.target.value)} placeholder="Choose…" options={taskOptions(tasks)} />
        <Select label="Training job" value={f.training_job_id} onChange={(e) => setF({ ...f, training_job_id: e.target.value })} placeholder={jobs.isLoading ? 'Loading…' : 'None'}
          options={(jobs.data?.jobs ?? []).map((j) => ({ value: String(j.id), label: `#${j.id} ${j.name}` }))} hint={jobs.error ? 'Training jobs could not be loaded.' : undefined} />
        <Input label="Candidate digest (sha256)" required className="font-mono" value={f.candidate_digest} error={errors.candidate_digest} onChange={(e) => setF({ ...f, candidate_digest: e.target.value })} />
        <Input label="Suite digest (sha256)" required className="font-mono" value={f.suite_digest} error={errors.suite_digest} onChange={(e) => setF({ ...f, suite_digest: e.target.value })} />
        <Input label="Baseline digest (sha256)" className="font-mono" value={f.baseline_digest} error={errors.baseline_digest} onChange={(e) => setF({ ...f, baseline_digest: e.target.value })} />
        <Input label="Hardware" placeholder="e.g. M2 Pro 32 GB" value={f.hardware} onChange={(e) => setF({ ...f, hardware: e.target.value })} />
        <Input label="Runtime lock" wrapperClassName="sm:col-span-2" placeholder="e.g. ollama 0.5.7" value={f.runtime_lock} onChange={(e) => setF({ ...f, runtime_lock: e.target.value })} />
      </div>
      <div className="grid gap-6 sm:grid-cols-2">
        <KVEditor label="Metrics" rows={metrics} onChange={setMetrics} hint={f.task ? 'Prefilled with the gate metrics for this task.' : 'Choose a task to prefill the gate metrics.'} />
        <KVEditor label="Baseline metrics" rows={baseline} onChange={setBaseline} hint="The active baseline on the same suite (optional)." />
      </div>
      <fieldset>
        <legend className="text-sm/6 font-medium text-slate-900 dark:text-white">Subgroups</legend>
        <p className="text-xs text-slate-500 dark:text-slate-400">Metrics as <code>key=value, key=value</code>. Strata under 100 items count as insufficient evidence.</p>
        <div className="mt-2 space-y-3">
          {subs.map((s, i) => {
            const upd = (p: Partial<SG>) => setSubs(subs.map((x, j) => (j === i ? { ...x, ...p } : x)));
            return (
              <div key={i} className="grid gap-2 rounded-md p-3 ring-1 ring-slate-200 sm:grid-cols-[1fr_6rem_auto] dark:ring-white/10">
                <Input aria-label="Subgroup name" placeholder="name (e.g. southern dialect)" value={s.name} onChange={(e) => upd({ name: e.target.value })} />
                <Input aria-label="Items (n)" placeholder="n" inputMode="numeric" value={s.n} onChange={(e) => upd({ n: e.target.value })} />
                <IconButton label="Remove subgroup" icon={TrashIcon} variant="ghost" size="sm" onClick={() => setSubs(subs.filter((_, j) => j !== i))} />
                <Input aria-label="Subgroup metrics" placeholder="wer_clean=0.18, wer_noisy=0.3" wrapperClassName="sm:col-span-3" value={s.metrics} onChange={(e) => upd({ metrics: e.target.value })} />
                <Input aria-label="Subgroup baseline" placeholder="baseline: wer_clean=0.17" wrapperClassName="sm:col-span-3" value={s.baseline} onChange={(e) => upd({ baseline: e.target.value })} />
              </div>
            );
          })}
          <Button size="sm" variant="ghost" icon={PlusIcon} onClick={() => setSubs([...subs, { name: '', n: '', metrics: '', baseline: '' }])}>Add subgroup</Button>
        </div>
      </fieldset>
      <Textarea label="Notes" rows={3} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} />
    </FormModal>
  );
}

/* ---------------------------------------------------------------- detail */

function EvaluationDetail({ id }: { id: string }) {
  const { can, user } = useAuth();
  const canSign = can('engine.sign');
  const toast = useToast();
  const qc = useQueryClient();
  const ov = useOverview();
  const ev = useQuery({ queryKey: ['engine', 'evaluation', id], queryFn: () => get<Evaluation>(`/engine/evaluations/${id}`) });
  const [role, setRole] = useState<'release' | 'language' | null>(null);

  const approve = useMutation({
    mutationFn: (r: 'release' | 'language') => post(`/engine/evaluations/${id}/approve`, { role: r }),
    onSuccess: () => { toast.success('Approval recorded'); setRole(null); void qc.invalidateQueries({ queryKey: ['engine'] }); },
    onError: (e) => { toast.error(refusalText(e)); setRole(null); },
  });

  const gateRows = useMemo(() => {
    const e = ev.data;
    if (!e) return [];
    const defs = ov.data?.gates?.[e.task] ?? {};
    return (e.gate_result?.checks ?? []).map((c) => {
      const d = defs[c.metric];
      return {
        ...c,
        threshold: d ? `${d[0] === 'max' ? '≤' : '≥'} ${fmtMetric(d[1])}` : c.label.includes('≤2 points') ? '≤ 0.02 Δ' : c.label.includes('≤5 points') ? '≤ 0.05 Δ' : '—',
        isDelta: !d && c.value !== undefined,
        baseline: e.baseline_metrics?.[c.metric],
      };
    });
  }, [ev.data, ov.data]);

  if (ev.error) return <><BackLink to="/engine/evaluations">Evaluations</BackLink><Card><ErrorState error={ev.error} onRetry={() => ev.refetch()} /></Card></>;
  if (ev.isLoading || !ev.data) return <><BackLink to="/engine/evaluations">Evaluations</BackLink><Skeleton className="mb-4 h-8 w-64" /><Card padded><Skeleton className="h-40 w-full" /></Card></>;
  const e = ev.data;
  const me = user?.id;
  const relBy = e.release_approver ? Number(e.release_approver) : null;
  const langBy = e.language_approver ? Number(e.language_approver) : null;

  const reasonFor = (r: 'release' | 'language'): string | null => {
    if (!canSign) return 'Needs the engine.sign permission.';
    if (e.decision !== 'pass') return 'Only passing evaluations can be approved.';
    if (r === 'release' && relBy) return 'Release owner approval already given.';
    if (r === 'language' && langBy) return 'Language lead approval already given.';
    if (r === 'release' && langBy && langBy === me) return 'You gave the language lead approval; the release owner must be a different person.';
    if (r === 'language' && relBy && relBy === me) return 'You gave the release owner approval; the language lead must be a different person.';
    return null;
  };

  return (
    <>
      <BackLink to="/engine/evaluations">Evaluations</BackLink>
      <PageHeader
        title={`${taskLabel(e.task)} evaluation #${e.id}`}
        meta={<div className="flex flex-wrap items-center gap-2"><DecisionBadge decision={e.decision} /><span className="text-sm text-slate-500 dark:text-slate-400">Created {dateTime(e.created_at)}{e.created_by_name ? ` by ${e.created_by_name}` : ''}</span></div>}
      />
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader title="Gate checklist" description="Every gate must pass; a missing metric is insufficient evidence, never a pass." />
            <DataTable
              rows={gateRows}
              rowKey={(r) => `${r.metric}-${r.label}`}
              empty={<EmptyState title="No gate result recorded" />}
              columns={[
                { key: 'gate', header: 'Gate', cell: (r) => <div><div>{r.label}</div><code className="text-xs text-slate-500 dark:text-slate-400">{r.metric}</code></div> },
                { key: 'st', header: 'Result', cell: (r) => <CheckBadge status={r.status} /> },
                { key: 'thr', header: 'Threshold', hideBelow: 'sm', cell: (r) => <span className="tabular-nums">{r.threshold}</span> },
                { key: 'val', header: 'Value', align: 'right', cell: (r) => <span className="tabular-nums">{r.value === undefined ? '—' : `${r.isDelta ? 'Δ ' : ''}${fmtMetric(r.value)}`}</span> },
                { key: 'base', header: 'Baseline', align: 'right', hideBelow: 'md', cell: (r) => <span className="tabular-nums">{fmtMetric(r.baseline)}</span> },
              ]}
            />
          </Card>

          <Card>
            <CardHeader title="Subgroups" />
            <DataTable
              rows={e.subgroups ?? []}
              rowKey={(s) => s.name}
              empty={<EmptyState title="No subgroups recorded" description="Stratified results (dialect, noise, speaker) appear here." />}
              columns={[
                { key: 'n', header: 'Subgroup', cell: (s) => s.name },
                { key: 'c', header: 'Items', align: 'right', cell: (s) => <span className="tabular-nums">{s.n}{Number(s.n) < 100 ? ' (insufficient)' : ''}</span> },
                { key: 'm', header: 'Metrics', cell: (s) => <span className="text-xs">{Object.entries(s.metrics ?? {}).map(([k, v]) => `${k} ${fmtMetric(v)}`).join(' · ') || '—'}</span> },
                { key: 'b', header: 'Baseline', hideBelow: 'md', cell: (s) => <span className="text-xs">{Object.entries(s.baseline ?? {}).map(([k, v]) => `${k} ${fmtMetric(v)}`).join(' · ') || '—'}</span> },
              ]}
            />
          </Card>

          {e.notes && <Card padded><h3 className="text-sm font-semibold text-slate-900 dark:text-white">Notes</h3><p className="mt-2 text-sm whitespace-pre-wrap text-slate-700 dark:text-slate-300">{e.notes}</p></Card>}
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Approvals" description="Release owner and language lead must be different people." />
            <div className="space-y-4 p-4 sm:p-6">
              {(['release', 'language'] as const).map((r) => {
                const given = r === 'release' ? relBy : langBy;
                const name = r === 'release' ? e.release_approver_name : e.language_approver_name;
                const reason = reasonFor(r);
                return (
                  <div key={r} className="space-y-2">
                    <ApprovalMark label={r === 'release' ? 'Release owner' : 'Language lead'} given={!!given} name={name} />
                    {!given && (
                      <>
                        <Button className="w-full" variant="primary" icon={CheckBadgeIcon} disabled={!!reason} onClick={() => setRole(r)}>
                          Approve as {r === 'release' ? 'release owner' : 'language lead'}
                        </Button>
                        {reason && <p className="text-xs text-slate-500 dark:text-slate-400">{reason}</p>}
                      </>
                    )}
                  </div>
                );
              })}
            </div>
          </Card>

          <Card>
            <DescriptionList items={[
              { label: 'Candidate', value: <Digest value={e.candidate_digest} /> },
              { label: 'Suite', value: <Digest value={e.suite_digest} /> },
              { label: 'Baseline', value: <Digest value={e.baseline_digest} /> },
              { label: 'Hardware', value: e.hardware || '—' },
              { label: 'Runtime lock', value: e.runtime_lock || '—' },
              { label: 'Training job', value: e.training_job ? <Link className="text-brand-600 hover:underline dark:text-blue-400" to="/training">#{e.training_job.id} {e.training_job.name}</Link> : e.training_job_id ? `#${e.training_job_id}` : '—' },
            ]} />
          </Card>

          <Card>
            <CardHeader title="Attached packages" />
            {e.packages?.length ? (
              <ul className="divide-y divide-slate-100 dark:divide-white/5">
                {e.packages.map((p) => (
                  <li key={p.id} className="flex items-center justify-between gap-2 px-4 py-3 sm:px-6">
                    <Link to={`/engine/packages/${p.id}`} className="text-sm font-medium text-brand-600 hover:underline dark:text-blue-400">v{p.version}</Link>
                    <StateBadge state={p.state} />
                  </li>
                ))}
              </ul>
            ) : <EmptyState title="No packages use this evaluation" description="Register a package and choose this evaluation." action={can('engine.manage') ? <Link to="/engine/packages?new=1" className="text-sm font-semibold text-brand-600 dark:text-blue-400">Register package</Link> : undefined} />}
          </Card>
          {e.decision !== 'pass' && <Alert tone="warning" title="Not approvable">This evaluation did not pass its release gate.</Alert>}
        </div>
      </div>

      <ConfirmDialog
        open={!!role}
        onClose={() => setRole(null)}
        onConfirm={() => role && approve.mutate(role)}
        busy={approve.isPending}
        tone="primary"
        confirmLabel="Approve"
        title={role === 'release' ? 'Approve as release owner?' : 'Approve as language lead?'}
        message={<>You confirm that evaluation #{e.id} (candidate <code className="font-mono">{e.candidate_digest.slice(0, 12)}</code>) meets the release bar. The approval is recorded under your name and cannot be withdrawn here.</>}
      />
    </>
  );
}
