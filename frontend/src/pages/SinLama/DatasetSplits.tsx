import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { Alert, Button, Card, CardHeader, ConfirmDialog, DescriptionList, Input, useToast } from '../../components/ui';
import { ApiError, post } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, num } from '../../lib/format';
import { Hash, RightsChip, type GovernedDataset } from './engineDataset';

const SPLITS = [
  { key: 'train', label: 'Train', bar: 'bg-blue-600 dark:bg-blue-500' },
  { key: 'dev', label: 'Dev', bar: 'bg-purple-500 dark:bg-purple-400' },
  { key: 'test', label: 'Test (locked)', bar: 'bg-amber-500 dark:bg-amber-400' },
  { key: 'unassigned', label: 'Unassigned', bar: 'bg-slate-300 dark:bg-slate-600' },
] as const;

/** S03 — grouped split counts, manifest/split hashes and Freeze vN+1 (POST /engine/datasets/{id}/freeze). */
export default function DatasetSplits({ dataset }: { dataset: GovernedDataset }) {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const toast = useToast();
  const qc = useQueryClient();
  const [train, setTrain] = useState('80');
  const [dev, setDev] = useState('10');
  const [confirm, setConfirm] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [lastCounts, setLastCounts] = useState<GovernedDataset['split_counts']>(null);

  const version = Number(dataset.version ?? 0);
  const status = dataset.rights_status ?? 'quarantined';
  const counts = dataset.split_counts ?? lastCounts;
  const cov = dataset.group_coverage;
  const t = Number(train), d = Number(dev);
  const ratioError = Number.isNaN(t) || Number.isNaN(d) || t <= 0 || d < 0 || t + d >= 100 ? 'Train must be above 0 and train + dev below 100.' : null;
  const total = counts ? SPLITS.reduce((s, x) => s + Number(counts[x.key] ?? 0), 0) : 0;

  const freeze = useMutation({
    mutationFn: () => post<GovernedDataset>(`/engine/datasets/${dataset.id}/freeze`, { train: t / 100, dev: d / 100 }),
    onSuccess: (r) => {
      setRefusal(null); setConfirm(false); setLastCounts(r?.split_counts ?? null);
      toast.success(`Frozen as v${r?.version ?? version + 1}`);
      qc.invalidateQueries({ queryKey: ['datasets'] });
    },
    onError: (e) => { setConfirm(false); setRefusal(e instanceof ApiError ? e.message : String(e)); toast.error(e); },
  });

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title={version ? `Version ${version}` : 'Not frozen yet'}
          description="Splits are assigned by group (speaker, document or session), never by row, so a group cannot leak across train/dev/test."
          actions={<RightsChip status={status} />}
        />
        <DescriptionList items={[
          { label: 'Frozen at', value: dataset.frozen_at ? dateTime(dataset.frozen_at) : 'Never' },
          { label: 'Manifest hash', value: <Hash value={dataset.manifest_hash} label="Manifest hash" /> },
          { label: 'Split hash', value: <Hash value={dataset.split_hash} label="Split hash" /> },
          {
            label: 'Group-key coverage',
            value: cov
              ? <>{num(cov.with)} rows with a group key · {num(cov.without)} without{Number(cov.without) > 0 && <span className="block text-xs text-slate-500 dark:text-slate-400">Rows without a group key are grouped by their own hash.</span>}</>
              : <span className="text-slate-500 dark:text-slate-400">Not reported by the server</span>,
          },
        ]} />
      </Card>

      <Card>
        <CardHeader title="Split counts" />
        <div className="p-4 sm:p-6">
          {counts && total > 0 ? (
            <>
              <div className="flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10" role="img" aria-label={SPLITS.map((s) => `${s.label} ${num(counts[s.key] ?? 0)}`).join(', ')}>
                {SPLITS.map((s) => <div key={s.key} className={s.bar} style={{ width: `${(Number(counts[s.key] ?? 0) / total) * 100}%` }} />)}
              </div>
              <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {SPLITS.map((s) => (
                  <div key={s.key}>
                    <dt className="flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400"><span className={`size-2.5 rounded-sm ${s.bar}`} aria-hidden />{s.label}</dt>
                    <dd className="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{num(counts[s.key] ?? 0)} <span className="text-sm font-normal text-slate-500 dark:text-slate-400">({((Number(counts[s.key] ?? 0) / total) * 100).toFixed(1)}%)</span></dd>
                  </div>
                ))}
              </dl>
            </>
          ) : (
            <p className="text-sm text-slate-500 dark:text-slate-400">
              {version ? 'Per-split counts are not reported by the server for this dataset yet.' : 'Freeze the dataset to assign train/dev/test splits.'}
            </p>
          )}
        </div>
      </Card>

      {canManage && (
        <Card>
          <CardHeader title={`Freeze v${version + 1}`} description="Test gets whatever is left after train and dev." />
          <div className="space-y-4 p-4 sm:p-6">
            {refusal && <Alert tone="danger" title="The server refused this">{refusal}</Alert>}
            {status !== 'approved' && <Alert tone="warning">Only rights-approved datasets can be frozen. Approve rights on the Governance tab first.</Alert>}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Input label="Train %" type="number" min={1} max={99} value={train} onChange={(e) => setTrain(e.target.value)} error={ratioError ?? undefined} />
              <Input label="Dev %" type="number" min={0} max={98} value={dev} onChange={(e) => setDev(e.target.value)} />
              <Input label="Test % (remainder)" value={ratioError ? '—' : String(Math.round((100 - t - d) * 100) / 100)} readOnly disabled />
            </div>
            <div className="flex justify-end">
              <Button icon={LockClosedIcon} disabled={!!ratioError || status !== 'approved'} onClick={() => setConfirm(true)}>Freeze v{version + 1}</Button>
            </div>
          </div>
        </Card>
      )}

      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => freeze.mutate()}
        busy={freeze.isPending}
        tone="primary"
        confirmLabel={`Freeze v${version + 1}`}
        title={`Freeze version ${version + 1}?`}
        message={
          <div className="space-y-2">
            <p>Splits {t}/{d}/{ratioError ? '—' : Math.round((100 - t - d) * 100) / 100} are assigned by group and a new manifest hash and split hash are recorded.</p>
            <p>The test split is locked for evaluation. Earlier versions' hashes are never edited — runs trained on them keep their lineage.</p>
          </div>
        }
      />
    </div>
  );
}
