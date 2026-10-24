import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightIcon, CheckBadgeIcon, InboxStackIcon, PencilSquareIcon } from '@heroicons/react/24/outline';
import { ClockIcon } from '@heroicons/react/20/solid';
import { Badge, Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorState, PageHeader, SkeletonRows, cx, useToast } from '../../components/ui';
import { get, post } from '../../lib/api';
import { ago } from '../../lib/format';
import { BackLink, DecisionBadge, Digest, StateBadge, refusalText, taskLabel, type Evaluation, type PackageSummary } from './shared';

type Role = 'release' | 'language';
interface EvalItem extends Evaluation { needs: Role[]; can_approve: boolean }
interface PkgItem extends PackageSummary { needs: 'sign' | 'activate' | 'verify'; can_act: boolean }
interface Payload { evaluations: EvalItem[]; packages: PkgItem[] }

const roleLabel = (r: Role) => (r === 'release' ? 'release owner' : 'language lead');
const needsLabel = { sign: 'signing (approved → staged)', activate: 'activation (bind to a profile)', verify: 'verification' };

type Pending = { kind: 'approve'; ev: EvalItem; role: Role } | { kind: 'sign'; pkg: PkgItem };

function Row({ actionable, children }: { actionable: boolean; children: ReactNode }) {
  return (
    <li className={cx('flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6', actionable ? 'bg-brand-50/50 dark:bg-blue-500/[0.06]' : 'opacity-90')}>
      {children}
    </li>
  );
}

function Waiting({ text }: { text: string }) {
  return <Badge tone="gray"><ClockIcon aria-hidden className="-ml-0.5 size-3.5" />Waiting for {text}</Badge>;
}

export default function EngineApprovals() {
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['engine', 'approvals'], queryFn: () => get<Payload>('/engine/approvals') });
  const [pending, setPending] = useState<Pending | null>(null);

  const act = useMutation({
    mutationFn: (p: Pending) => (p.kind === 'approve'
      ? post(`/engine/evaluations/${p.ev.id}/approve`, { role: p.role })
      : post(`/engine/packages/${p.pkg.id}/transition`, { to: 'staged' })),
    onSuccess: (_d, p) => { toast.success(p.kind === 'approve' ? 'Approval recorded' : 'Package signed and staged'); setPending(null); void qc.invalidateQueries({ queryKey: ['engine'] }); },
    onError: (e) => { toast.error(refusalText(e)); setPending(null); },
  });

  const evs = [...(q.data?.evaluations ?? [])].sort((a, b) => Number(b.can_approve) - Number(a.can_approve));
  const pkgs = [...(q.data?.packages ?? [])].sort((a, b) => Number(b.can_act) - Number(a.can_act));

  return (
    <>
      <BackLink to="/engine">Engine overview</BackLink>
      <PageHeader title="Approvals" description="Evaluations waiting for a release owner or language lead, and packages waiting to be signed or activated. Items you can act on are listed first." />
      {q.error ? <Card><ErrorState error={q.error} onRetry={() => q.refetch()} /></Card> : (
        <div className="space-y-6">
          <Card>
            <CardHeader title="Evaluations awaiting approval" description={q.data ? `${evs.filter((e) => e.can_approve).length} of ${evs.length} actionable by you` : undefined} />
            {q.isLoading ? <SkeletonRows rows={3} /> : !evs.length ? (
              <EmptyState icon={InboxStackIcon} title="No evaluations waiting" description="Passing evaluations that still need an approval appear here." action={<Link to="/engine/evaluations" className="text-sm font-semibold text-brand-600 dark:text-blue-400">View evaluations</Link>} />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-white/5">
                {evs.map((e) => (
                  <Row key={e.id} actionable={e.can_approve}>
                    <div className="min-w-0 space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Link to={`/engine/evaluations/${e.id}`} className="font-medium text-slate-900 hover:underline dark:text-white">{taskLabel(e.task)} evaluation #{e.id}</Link>
                        <DecisionBadge decision={e.decision} />
                      </div>
                      <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <span>Candidate</span><Digest value={e.candidate_digest} copy={false} /><span>· {ago(e.created_at)}</span>
                      </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      {e.can_approve
                        ? (e.needs ?? []).map((r) => <Button key={r} size="sm" variant="primary" icon={CheckBadgeIcon} onClick={() => setPending({ kind: 'approve', ev: e, role: r })}>Approve as {roleLabel(r)}</Button>)
                        : <Waiting text={(e.needs ?? []).map(roleLabel).join(' and ') || 'approval'} />}
                      <Link to={`/engine/evaluations/${e.id}`} className="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 dark:text-blue-400">Open <ArrowRightIcon aria-hidden className="size-4" /></Link>
                    </div>
                  </Row>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Packages awaiting signing or activation" description={q.data ? `${pkgs.filter((p) => p.can_act).length} of ${pkgs.length} actionable by you` : undefined} />
            {q.isLoading ? <SkeletonRows rows={3} /> : !pkgs.length ? (
              <EmptyState icon={InboxStackIcon} title="No packages waiting" description="Approved packages wait here to be signed; staged ones wait to be activated." action={<Link to="/engine/packages" className="text-sm font-semibold text-brand-600 dark:text-blue-400">View registry</Link>} />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-white/5">
                {pkgs.map((p) => (
                  <Row key={p.id} actionable={p.can_act}>
                    <div className="min-w-0 space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Link to={`/engine/packages/${p.id}`} className="font-medium text-slate-900 hover:underline dark:text-white">{taskLabel(p.task)} v{p.version}</Link>
                        <StateBadge state={p.state} />
                      </div>
                      <p className="text-xs text-slate-500 dark:text-slate-400">Needs {needsLabel[p.needs] ?? p.needs} · {p.runtime_name} · {ago(p.updated_at)}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      {!p.can_act ? <Waiting text={needsLabel[p.needs] ?? p.needs} />
                        : p.needs === 'sign' ? <Button size="sm" variant="primary" icon={PencilSquareIcon} onClick={() => setPending({ kind: 'sign', pkg: p })}>Sign</Button>
                        : p.needs === 'activate' ? <Link to="/engine/bindings" className="inline-flex items-center gap-1 rounded-md bg-brand-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-brand-500">Activate in bindings</Link>
                        : null}
                      <Link to={`/engine/packages/${p.id}`} className="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 dark:text-blue-400">Open <ArrowRightIcon aria-hidden className="size-4" /></Link>
                    </div>
                  </Row>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      <ConfirmDialog
        open={!!pending}
        onClose={() => setPending(null)}
        onConfirm={() => pending && act.mutate(pending)}
        busy={act.isPending}
        tone="primary"
        confirmLabel={pending?.kind === 'sign' ? 'Sign package' : 'Approve'}
        title={pending?.kind === 'sign' ? 'Sign this package?' : `Approve as ${pending?.kind === 'approve' ? roleLabel(pending.role) : ''}?`}
        message={pending?.kind === 'sign'
          ? <>The manifest of {taskLabel(pending.pkg.task)} v{pending.pkg.version} is signed with the publisher key and the package moves to staged. Signing cannot be undone; only revocation removes it.</>
          : pending?.kind === 'approve'
            ? <>Evaluation #{pending.ev.id} ({taskLabel(pending.ev.task)}) is approved under your name. The other approval must come from a different person.</>
            : null}
      />
    </>
  );
}
