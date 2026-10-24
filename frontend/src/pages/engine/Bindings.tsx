import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowUturnLeftIcon, LinkIcon, PlayIcon } from '@heroicons/react/24/outline';
import { Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorState, FormModal, PageHeader, Select, SkeletonRows, useToast } from '../../components/ui';
import { get, post, put } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ago, dateTime } from '../../lib/format';
import { BackLink, Digest, StateBadge, profileLabel, refusalText, short, taskLabel, usePackages, type PackageSummary } from './shared';

interface BindingRow { task: string; profile: string; active: PackageSummary | null; previous: PackageSummary | null; updated_by_name: string | null; updated_at: string | null }
interface Payload { profiles: string[]; tasks: string[]; rows: BindingRow[] }

const pkgText = (p: PackageSummary | null | undefined) => (p ? `v${p.version} (${short(p.manifest_sha256, 10)})` : 'nothing');

type Pending =
  | { kind: 'activate'; task: string; profile: string; from: PackageSummary | null; to: PackageSummary }
  | { kind: 'rollback'; task: string; profile: string; from: PackageSummary | null; to: PackageSummary | null };

export default function EngineBindings() {
  const { can } = useAuth();
  const canSign = can('engine.sign');
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['engine', 'bindings'], queryFn: () => get<Payload>('/engine/bindings') });
  const pkgs = usePackages();
  const [picking, setPicking] = useState<{ task: string; profile: string; from: PackageSummary | null } | null>(null);
  const [pick, setPick] = useState('');
  const [pending, setPending] = useState<Pending | null>(null);

  const act = useMutation({
    mutationFn: (p: Pending) => (p.kind === 'activate'
      ? put<Payload>('/engine/bindings', { task: p.task, profile: p.profile, package_id: p.to.id })
      : post<Payload>('/engine/bindings/rollback', { task: p.task, profile: p.profile })),
    onSuccess: (data, p) => {
      if (data) qc.setQueryData(['engine', 'bindings'], data);
      toast.success(p.kind === 'activate' ? `Activated ${pkgText(p.to)} on ${profileLabel(p.profile)}` : `Rolled back ${taskLabel(p.task)} on ${profileLabel(p.profile)}`);
      setPending(null);
      void qc.invalidateQueries({ queryKey: ['engine'] });
    },
    onError: (e) => { toast.error(refusalText(e)); setPending(null); },
  });

  const profiles = q.data?.profiles ?? ['minimum', 'standard', 'apple', 'accelerated'];
  const tasks = q.data?.tasks ?? [];
  const find = (task: string, profile: string) => q.data?.rows.find((r) => r.task === task && r.profile === profile);
  const signedFor = (task: string) => (pkgs.data ?? []).filter((p) => p.task === task && (p.state === 'staged' || p.state === 'active'));

  const startActivate = (task: string, profile: string, from: PackageSummary | null) => { setPick(''); setPicking({ task, profile, from }); };

  return (
    <>
      <BackLink to="/engine">Engine overview</BackLink>
      <PageHeader title="Bindings" description="Which signed package each hardware profile runs for each task. Activation keeps the previous package for one-step rollback." />

      {q.error ? <Card><ErrorState error={q.error} onRetry={() => q.refetch()} /></Card>
        : q.isLoading ? <Card><SkeletonRows rows={4} cols={5} /></Card>
        : !tasks.length ? (
          <Card><EmptyState icon={LinkIcon} title="No tasks to bind" description="Sign a package first; signed packages can be activated here." action={<Link to="/engine/packages" className="text-sm font-semibold text-brand-600 dark:text-blue-400">Open registry</Link>} /></Card>
        ) : (
          <div className="space-y-6">
            {tasks.map((task) => {
              const candidates = signedFor(task);
              return (
                <Card key={task}>
                  <CardHeader title={taskLabel(task)} description={`${candidates.length} signed package${candidates.length === 1 ? '' : 's'} available`} />
                  <div className="grid divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 dark:divide-white/5">
                    {profiles.map((profile) => {
                      const row = find(task, profile);
                      const active = row?.active ?? null;
                      const prev = row?.previous ?? null;
                      return (
                        <section key={profile} aria-label={`${taskLabel(task)} — ${profileLabel(profile)}`} className="space-y-3 p-4 sm:border-r sm:border-b sm:border-slate-100 sm:p-5 dark:sm:border-white/5">
                          <h4 className="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">{profileLabel(profile)}</h4>
                          <div>
                            <div className="text-xs text-slate-500 dark:text-slate-400">Active</div>
                            {active ? (
                              <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                <Link to={`/engine/packages/${active.id}`} className="text-sm font-semibold text-brand-600 hover:underline dark:text-blue-400">v{active.version}</Link>
                                <StateBadge state={active.state} />
                                <Digest value={active.manifest_sha256} n={10} />
                              </div>
                            ) : <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Nothing active</p>}
                          </div>
                          <div>
                            <div className="text-xs text-slate-500 dark:text-slate-400">Previous</div>
                            {prev ? (
                              <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                <Link to={`/engine/packages/${prev.id}`} className="text-sm text-slate-700 hover:underline dark:text-slate-300">v{prev.version}</Link>
                                <StateBadge state={prev.state} />
                              </div>
                            ) : <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">None</p>}
                          </div>
                          {row?.updated_at && <p className="text-xs text-slate-500 dark:text-slate-400" title={dateTime(row.updated_at)}>Updated {ago(row.updated_at)}{row.updated_by_name ? ` by ${row.updated_by_name}` : ''}</p>}
                          {canSign && (
                            <div className="flex flex-wrap gap-2">
                              <Button size="sm" variant="primary" icon={PlayIcon} disabled={!candidates.length} onClick={() => startActivate(task, profile, active)}>Activate</Button>
                              <Button size="sm" icon={ArrowUturnLeftIcon} disabled={!prev} onClick={() => setPending({ kind: 'rollback', task, profile, from: active, to: prev })}>Roll back</Button>
                            </div>
                          )}
                          {canSign && !candidates.length && <p className="text-xs text-slate-500 dark:text-slate-400">No signed package for this task yet.</p>}
                          {canSign && !prev && <p className="text-xs text-slate-500 dark:text-slate-400">Roll back needs a previous package.</p>}
                        </section>
                      );
                    })}
                  </div>
                </Card>
              );
            })}
          </div>
        )}

      <FormModal
        open={!!picking}
        onClose={() => setPicking(null)}
        title={picking ? `Activate ${taskLabel(picking.task)} on ${profileLabel(picking.profile)}` : ''}
        description="Only signed (staged or active) packages of this task can be activated."
        submitLabel="Continue"
        onSubmit={() => {
          const to = (pkgs.data ?? []).find((p) => String(p.id) === pick);
          if (!picking || !to) { toast.error('Choose a package.'); return; }
          setPending({ kind: 'activate', task: picking.task, profile: picking.profile, from: picking.from, to });
          setPicking(null);
        }}
      >
        {picking && (
          <Select label="Package" required value={pick} onChange={(e) => setPick(e.target.value)} placeholder="Choose…"
            options={signedFor(picking.task).map((p) => ({ value: String(p.id), label: `v${p.version} — ${p.state} — ${short(p.manifest_sha256, 10)}`, disabled: p.id === picking.from?.id }))} />
        )}
      </FormModal>

      <ConfirmDialog
        open={!!pending}
        onClose={() => setPending(null)}
        onConfirm={() => pending && act.mutate(pending)}
        busy={act.isPending}
        tone={pending?.kind === 'rollback' ? 'danger' : 'primary'}
        confirmLabel={pending?.kind === 'rollback' ? 'Roll back' : 'Activate'}
        title={pending ? `${pending.kind === 'rollback' ? 'Roll back' : 'Activate'} ${taskLabel(pending.task)} on ${profileLabel(pending.profile)}?` : ''}
        message={pending ? (
          <div className="space-y-2">
            <p><span className="font-medium">From:</span> <code className="font-mono">{pkgText(pending.from)}</code></p>
            <p><span className="font-medium">To:</span> <code className="font-mono">{pkgText(pending.to)}</code></p>
            <p>Clients on this profile switch at their next check. The package being replaced is kept as the previous one.</p>
          </div>
        ) : null}
      />
    </>
  );
}
