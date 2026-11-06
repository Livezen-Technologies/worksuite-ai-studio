import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowDownTrayIcon, ArrowPathIcon, ArrowUturnLeftIcon, PencilSquareIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, FormModal, Input, Segmented, Select, useToast } from '../../components/ui';
import { download, get, post, put } from '../../lib/api';
import { ago, bytes, dateTime } from '../../lib/format';
import { RUN_TONE, type Backup, type BackupRun, type Policy, type RestoreStatus } from './shared';

export function BackupsTab({ manage }: { manage: boolean }) {
  const toast = useToast();
  const q = useQuery({ queryKey: ['storage-backups'], queryFn: () => get<Backup[]>('/storage/backups'), refetchInterval: 15_000 });
  const [editing, setEditing] = useState<Backup | null>(null);
  const [restoring, setRestoring] = useState<{ backup: Backup; run: BackupRun } | null>(null);
  const rs = useQuery({ queryKey: ['storage-restore'], queryFn: () => get<RestoreStatus | null>('/storage/restore'),
    refetchInterval: (query) => (query.state.data?.state === 'running' ? 3000 : 30_000) });
  const run = useMutation({ mutationFn: (b: Backup) => post(`/storage/backups/${b.id}/run`), onSuccess: () => { toast.success('Backup started', 'It runs in the background.'); q.refetch(); }, onError: (e) => toast.error(e) });
  return (
    <div className="space-y-6">
      {rs.data && <RestoreBanner s={rs.data} />}
      {(q.data ?? []).map((b) => (
        <Card key={b.id}>
          <CardHeader title={<span className="flex items-center gap-2">{b.name} <Badge tone={Number(b.enabled) === 1 ? 'green' : 'gray'}>{Number(b.enabled) === 1 ? 'on' : 'off'}</Badge></span>}
            description={`Database dump, ${b.schedule} at ${String(b.at_hour).padStart(2, '0')}:00 server time, keeps the newest ${b.keep}. Stored as files in app "backups" — give it a policy with copies in two regions for off-site recovery.`}
            actions={manage && (
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" icon={PencilSquareIcon} onClick={() => setEditing(b)}>Settings</Button>
                <Button size="sm" icon={ArrowPathIcon} disabled={run.isPending || b.runs[0]?.status === 'running'} onClick={() => run.mutate(b)}>Run now</Button>
              </div>
            )} />
          <ul className="divide-y divide-slate-200 dark:divide-white/10">
            {b.runs.map((r) => (
              <li key={r.id} className="flex flex-col gap-1 px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:gap-3 sm:px-6">
                <Badge tone={RUN_TONE[r.status] ?? 'gray'}>{r.status}</Badge>
                <span className="text-slate-700 dark:text-slate-300" title={dateTime(r.started_at)}>{ago(r.started_at)} · {r.trigger}</span>
                <span className="flex-1 truncate text-xs text-slate-500 dark:text-slate-400">{r.error ?? (r.object_key ? `${r.object_key}${r.size ? ` · ${bytes(r.size)}` : ''}` : r.object_id ? 'removed by the keep limit' : '')}</span>
                {r.status === 'ok' && r.object_key && r.object_id && <Button size="sm" variant="secondary" icon={ArrowDownTrayIcon} onClick={() => download(`/storage/files/${r.object_id}/download`, 'backup.sql.gz').catch((e) => toast.error(e))}>Download</Button>}
                {manage && r.status === 'ok' && r.object_key && <Button size="sm" variant="secondary" icon={ArrowUturnLeftIcon} disabled={rs.data?.state === 'running'} onClick={() => setRestoring({ backup: b, run: r })}>Restore</Button>}
              </li>
            ))}
            {!b.runs.length && <li className="px-4 py-3 text-sm text-slate-500 sm:px-6 dark:text-slate-400">No runs yet.</li>}
          </ul>
        </Card>
      ))}
      {restoring && <RestoreDialog {...restoring} onClose={() => setRestoring(null)} onStarted={() => { setRestoring(null); rs.refetch(); }} />}
      {editing && <BackupForm backup={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); q.refetch(); }} />}
    </div>
  );
}

function BackupForm({ backup, onClose, onSaved }: { backup: Backup; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const pol = useQuery({ queryKey: ['storage-policies'], queryFn: () => get<Policy[]>('/storage/policies') });
  const [schedule, setSchedule] = useState(backup.schedule);
  const [hour, setHour] = useState(String(backup.at_hour));
  const [keep, setKeep] = useState(String(backup.keep));
  const [policy, setPolicy] = useState(String(backup.policy_id ?? ''));
  const [exclude, setExclude] = useState(backup.exclude_tables ?? '');
  const [enabled, setEnabled] = useState(Number(backup.enabled) === 1);
  const save = useMutation({
    mutationFn: () => put(`/storage/backups/${backup.id}`, { schedule, at_hour: Number(hour), keep: Number(keep), policy_id: policy ? Number(policy) : null, exclude_tables: exclude, enabled }),
    onSuccess: () => { toast.success('Backup saved'); onSaved(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={`${backup.name} — settings`} onSubmit={() => save.mutate()} submitting={save.isPending}>
      <Segmented label="Backups" value={enabled ? 'on' : 'off'} onChange={(v) => setEnabled(v === 'on')} options={[{ value: 'off', label: 'Off' }, { value: 'on', label: 'On' }]} />
      <div className="grid gap-4 sm:grid-cols-3">
        <Select label="Every" value={schedule} onChange={(e) => setSchedule(e.target.value)} options={[{ value: 'daily', label: 'Day' }, { value: 'weekly', label: 'Week' }]} />
        <Input label="At hour" type="number" min={0} max={23} value={hour} onChange={(e) => setHour(e.target.value)} hint="Server time (UTC)" />
        <Input label="Keep" type="number" min={1} max={365} value={keep} onChange={(e) => setKeep(e.target.value)} hint="Newest runs kept" />
      </div>
      <Select label="Placement policy" value={policy} onChange={(e) => setPolicy(e.target.value)}
        options={[{ value: '', label: 'The default policy' }, ...(pol.data ?? []).map((p) => ({ value: String(p.id), label: `${p.name} — ${p.replicas} copies${Number(p.min_regions ?? 1) > 1 ? `, ${p.min_regions}+ regions` : ''}` }))]} />
      <Input label="Leave out tables" value={exclude} onChange={(e) => setExclude(e.target.value)} hint="Comma-separated table names, e.g. large logs that can be rebuilt." />
    </FormModal>
  );
}

function RestoreBanner({ s }: { s: RestoreStatus }) {
  const tone = s.state === 'running' ? 'border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10'
    : s.state === 'failed' ? 'border-red-300 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10' : 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10';
  return (
    <div className={`rounded-lg border p-4 text-sm ${tone}`} role="status">
      <p className="font-medium text-slate-900 dark:text-white">
        {s.state === 'running' ? 'Restoring the database…' : s.state === 'failed' ? 'The last restore failed' : 'Last restore finished'} — backup of {s.taken_at}, started by {s.by} {ago(s.started_at)}
      </p>
      <p className="mt-1 text-slate-700 dark:text-slate-300">{s.state === 'failed' ? s.error : s.step}</p>
      {s.safety_key && <p className="mt-1 text-xs text-slate-600 dark:text-slate-400">The database as it was before is kept as {s.safety_key} — restore it to undo.</p>}
    </div>
  );
}

function RestoreDialog({ backup, run, onClose, onStarted }: { backup: Backup; run: BackupRun; onClose: () => void; onStarted: () => void }) {
  const toast = useToast();
  const [confirm, setConfirm] = useState('');
  const [safety, setSafety] = useState<'yes' | 'no'>('yes');
  const go = useMutation({
    mutationFn: () => post(`/storage/backups/runs/${run.id}/restore`, { confirm, safety: safety === 'yes' }),
    onSuccess: () => { toast.success('Restore started', 'Progress shows at the top of this tab.'); onStarted(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} danger title={`Restore the database to ${dateTime(run.started_at)}?`} submitLabel="Restore database" submitting={go.isPending}
      onSubmit={() => { if (confirm === 'RESTORE') go.mutate(); }}
      description={`Everything in AI Studio — users, datasets, jobs, settings — goes back to how it was when this ${backup.name} backup was taken. Changes made since then are lost. The Studio may answer slowly or with errors while it runs. Only a Super Admin can do this.`}>
      <Segmented label="Safety backup" value={safety} onChange={setSafety}
        options={[{ value: 'yes', label: 'Back up the current database first (recommended)' }, { value: 'no', label: 'Skip — I have a recent backup' }]} />
      <p className="text-xs text-slate-500 dark:text-slate-400">The safety backup is how you undo this restore. If it fails, nothing is restored.{backup.exclude_tables ? ` Tables this backup leaves out (${backup.exclude_tables}) keep their current contents.` : ''}</p>
      <Input label="Type RESTORE to confirm" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off" />
    </FormModal>
  );
}
