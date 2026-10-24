import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckBadgeIcon, NoSymbolIcon } from '@heroicons/react/24/outline';
import { Alert, Button, Card, CardHeader, Checkbox, ConfirmDialog, Input, Select, StatusBadge, useToast } from '../../components/ui';
import { ApiError, get, post, put } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { DATASET_TASKS, RightsChip, type GovernedDataset } from './engineDataset';

interface Run { id: number; name: string; status: string; dataset_id: number | null }

/** S02 — provenance, rights and consent for one dataset (PUT /engine/datasets/{id}/rights, POST …/withdraw). */
export default function DatasetGovernance({ dataset }: { dataset: GovernedDataset }) {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const toast = useToast();
  const qc = useQueryClient();
  const [f, setF] = useState(() => ({
    task: dataset.task ?? 'text', source_ref: dataset.source_ref ?? '', permitted_use: dataset.permitted_use ?? '',
    rights_ref: dataset.rights_ref ?? '', consent_required: Boolean(Number(dataset.consent_required ?? 0)), consent_ref: dataset.consent_ref ?? '',
  }));
  const [confirm, setConfirm] = useState<'approve' | 'withdraw' | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [withdrawNote, setWithdrawNote] = useState<string | null>(null);
  const status = dataset.rights_status ?? 'quarantined';
  const set = (k: keyof typeof f, v: string | boolean) => setF((x) => ({ ...x, [k]: v }));

  const runs = useQuery({ queryKey: ['training'], queryFn: () => get<{ jobs: Run[] }>('/training') });
  const affected = (runs.data?.jobs ?? []).filter((j) => Number(j.dataset_id) === dataset.id);

  const missing = [
    !f.source_ref.trim() && 'Source reference',
    !f.permitted_use.trim() && 'Permitted use',
    !f.rights_ref.trim() && 'Rights reference',
    f.consent_required && !f.consent_ref.trim() && 'Consent reference (consent is required)',
  ].filter(Boolean) as string[];

  const refresh = () => qc.invalidateQueries({ queryKey: ['datasets'] });
  const fail = (e: unknown) => { setRefusal(e instanceof ApiError ? e.message : String(e)); toast.error(e); };

  const save = useMutation({
    mutationFn: (approve: boolean) => put<GovernedDataset>(`/engine/datasets/${dataset.id}/rights`, {
      task: f.task, source_ref: f.source_ref.trim() || null, permitted_use: f.permitted_use.trim() || null,
      rights_ref: f.rights_ref.trim() || null, consent_required: f.consent_required, consent_ref: f.consent_ref.trim() || null,
      ...(approve ? { approve: true } : {}),
    }),
    onSuccess: (_, approve) => { setRefusal(null); setConfirm(null); toast.success(approve ? 'Rights approved' : 'Governance saved'); refresh(); },
    onError: (e) => { setConfirm(null); fail(e); },
  });
  const withdraw = useMutation({
    mutationFn: () => post<{ note?: string; affected_runs?: Run[] }>(`/engine/datasets/${dataset.id}/withdraw`),
    onSuccess: (r) => { setRefusal(null); setConfirm(null); setWithdrawNote(r?.note ?? null); toast.success('Dataset withdrawn'); refresh(); },
    onError: (e) => { setConfirm(null); fail(e); },
  });

  const ro = !canManage || status === 'withdrawn';

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title="Rights & provenance"
          description="Datasets stay quarantined until an approved rights record exists. Only approved datasets can be frozen and trained on."
          actions={<RightsChip status={status} />}
        />
        <div className="space-y-4 p-4 sm:p-6">
          {refusal && <Alert tone="danger" title="The server refused this">{refusal}</Alert>}
          {withdrawNote && <Alert tone="warning">{withdrawNote}</Alert>}
          {status === 'withdrawn' && !withdrawNote && (
            <Alert tone="warning" title="Withdrawn">Excluded from future training. Existing trained runs keep their lineage and are not automatically unlearned.</Alert>
          )}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select label="Task" value={f.task} disabled={ro} onChange={(e) => set('task', e.target.value)} options={DATASET_TASKS.map((t) => ({ value: t, label: t }))} />
            <Input label="Source reference" value={f.source_ref} disabled={ro} onChange={(e) => set('source_ref', e.target.value)} placeholder="Where the data came from (URL, contract, corpus id)" />
            <Input label="Permitted use" value={f.permitted_use} disabled={ro} onChange={(e) => set('permitted_use', e.target.value)} placeholder="e.g. commercial model training" />
            <Input label="Rights reference" value={f.rights_ref} disabled={ro} onChange={(e) => set('rights_ref', e.target.value)} placeholder="Licence / agreement id" />
            <div className="sm:col-span-2">
              <Checkbox checked={f.consent_required} disabled={ro} onChange={(v) => set('consent_required', v)} label="Contributor consent is required" />
            </div>
            {f.consent_required && (
              <Input label="Consent reference" required value={f.consent_ref} disabled={ro} onChange={(e) => set('consent_ref', e.target.value)} wrapperClassName="sm:col-span-2" />
            )}
          </div>
          {canManage && status === 'quarantined' && missing.length > 0 && (
            <Alert tone="info" title="Before rights can be approved, fill in:">
              <ul className="list-disc pl-5">{missing.map((m) => <li key={m}>{m}</li>)}</ul>
            </Alert>
          )}
          {canManage && status !== 'withdrawn' && (
            <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
              <Button variant="secondary" loading={save.isPending && save.variables === false} onClick={() => save.mutate(false)}>Save</Button>
              {status !== 'approved' && (
                <Button icon={CheckBadgeIcon} disabled={missing.length > 0} title={missing.length ? `Missing: ${missing.join(', ')}` : undefined} onClick={() => setConfirm('approve')}>
                  Approve rights
                </Button>
              )}
              <Button variant="danger" icon={NoSymbolIcon} onClick={() => setConfirm('withdraw')}>Withdraw</Button>
            </div>
          )}
        </div>
      </Card>

      <Card>
        <CardHeader title="Affected runs" description="Training jobs that use this dataset." />
        <div className="p-4 sm:p-6">
          <RunList runs={affected} loading={runs.isLoading} />
        </div>
      </Card>

      <ConfirmDialog
        open={confirm === 'approve'}
        onClose={() => setConfirm(null)}
        onConfirm={() => save.mutate(true)}
        busy={save.isPending}
        tone="primary"
        confirmLabel="Approve rights"
        title="Approve this dataset's rights?"
        message="The current source, permitted use, rights and consent references are saved and the dataset leaves quarantine. It can then be frozen for training."
      />
      <ConfirmDialog
        open={confirm === 'withdraw'}
        onClose={() => setConfirm(null)}
        onConfirm={() => withdraw.mutate()}
        busy={withdraw.isPending}
        confirmLabel="Withdraw dataset"
        title="Withdraw this dataset?"
        message={
          <div className="space-y-3">
            <p>It will be excluded from every future training run. Models already trained on it are not automatically unlearned.</p>
            <p className="font-medium">Affected runs ({affected.length}):</p>
            <RunList runs={affected} loading={runs.isLoading} />
          </div>
        }
      />
    </div>
  );
}

function RunList({ runs, loading }: { runs: Run[]; loading: boolean }) {
  if (loading) return <p className="text-sm text-slate-500 dark:text-slate-400">Loading…</p>;
  if (!runs.length) return <p className="text-sm text-slate-500 dark:text-slate-400">No training jobs use this dataset.</p>;
  return (
    <ul className="divide-y divide-slate-100 text-sm dark:divide-white/5">
      {runs.map((r) => (
        <li key={r.id} className="flex items-center justify-between gap-3 py-2">
          <span className="min-w-0 truncate text-slate-900 dark:text-slate-100">#{r.id} {r.name}</span>
          <StatusBadge status={r.status} />
        </li>
      ))}
    </ul>
  );
}
