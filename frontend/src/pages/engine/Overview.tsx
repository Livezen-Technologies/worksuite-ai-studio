import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRightIcon, BeakerIcon, CubeIcon, InboxStackIcon, KeyIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Button, Card, CardHeader, DataTable, EmptyState, ErrorState, PageHeader, Skeleton, type Column } from '../../components/ui';
import { get } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ago } from '../../lib/format';
import {
  CheckBadge, CopyButton, Digest, LifecycleStepper, StateBadge, taskLabel, useEvaluations, useOverview, usePackages,
  type Evaluation, type PackageSummary,
} from './shared';

interface ApprovalsPayload { evaluations: { can_approve?: boolean }[]; packages: { can_act?: boolean }[] }

export default function EngineOverview() {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const nav = useNavigate();
  const ov = useOverview();
  const pkgs = usePackages();
  const evals = useEvaluations();
  const approvals = useQuery({ queryKey: ['engine', 'approvals'], queryFn: () => get<ApprovalsPayload>('/engine/approvals') });

  const tasks = Object.keys(ov.data?.gates ?? {});
  const activeRows = tasks.map((task) => ({ task, pkg: (pkgs.data ?? []).find((p) => p.task === task && p.state === 'active') ?? null }));
  const failing = (evals.data ?? []).filter((e) => e.decision === 'fail');
  const pendingTotal = (approvals.data?.evaluations?.length ?? 0) + (approvals.data?.packages?.length ?? 0);
  const pendingMine = (approvals.data?.evaluations?.filter((e) => e.can_approve).length ?? 0) + (approvals.data?.packages?.filter((p) => p.can_act).length ?? 0);

  const activeCols: Column<{ task: string; pkg: PackageSummary | null }>[] = [
    { key: 'task', header: 'Task', cell: (r) => taskLabel(r.task) },
    { key: 'ver', header: 'Active package', cell: (r) => (r.pkg ? <Link className="font-medium text-brand-600 hover:underline dark:text-blue-400" to={`/engine/packages/${r.pkg.id}`}>v{r.pkg.version}</Link> : <span className="text-slate-500 dark:text-slate-400">None active</span>) },
    { key: 'state', header: 'State', hideBelow: 'sm', cell: (r) => (r.pkg ? <StateBadge state="active" /> : '—') },
    { key: 'digest', header: 'Manifest', hideBelow: 'md', cell: (r) => <Digest value={r.pkg?.manifest_sha256} /> },
    { key: 'rt', header: 'Runtime', hideBelow: 'lg', cell: (r) => r.pkg?.runtime_name ?? '—' },
  ];

  const failCols: Column<Evaluation>[] = [
    { key: 'task', header: 'Task', cell: (e) => taskLabel(e.task) },
    { key: 'gates', header: 'Failing gates', cell: (e) => {
      const bad = (e.gate_result?.checks ?? []).filter((c) => c.status !== 'pass');
      return (
        <ul className="space-y-1">
          {bad.slice(0, 3).map((c) => <li key={c.metric} className="flex flex-wrap items-center gap-2"><CheckBadge status={c.status} /><span className="text-xs">{c.label}</span></li>)}
          {bad.length > 3 && <li className="text-xs text-slate-500 dark:text-slate-400">and {bad.length - 3} more</li>}
        </ul>
      );
    } },
    { key: 'cand', header: 'Candidate', hideBelow: 'md', cell: (e) => <Digest value={e.candidate_digest} copy={false} /> },
    { key: 'at', header: 'Created', hideBelow: 'sm', cell: (e) => ago(e.created_at) },
  ];

  return (
    <>
      <PageHeader
        title="Sinhala Engine"
        description="Release core: what is active, what is moving through the pipeline, and what needs a decision."
        actions={canManage ? (
          <>
            <Button icon={BeakerIcon} onClick={() => nav('/engine/evaluations?new=1')}>New evaluation</Button>
            <Button variant="primary" icon={PlusIcon} onClick={() => nav('/engine/packages?new=1')}>Register package</Button>
          </>
        ) : undefined}
      />

      <div className="space-y-6">
        <Card>
          <CardHeader title="Pipeline" description="Packages in each lifecycle state." actions={<Link to="/engine/packages" className="text-sm font-semibold text-brand-600 dark:text-blue-400">Registry</Link>} />
          <div className="p-4 sm:p-6">
            {ov.error ? <ErrorState error={ov.error} onRetry={() => ov.refetch()} />
              : ov.isLoading ? <Skeleton className="h-16 w-full" />
              : <LifecycleStepper counts={ov.data?.package_counts ?? {}} />}
          </div>
        </Card>

        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardHeader title="Active package per task" />
            <DataTable
              columns={activeCols}
              rows={ov.data && pkgs.data ? activeRows : undefined}
              rowKey={(r) => r.task}
              loading={ov.isLoading || pkgs.isLoading}
              error={ov.error || pkgs.error}
              onRetry={() => { void ov.refetch(); void pkgs.refetch(); }}
              empty={<EmptyState icon={CubeIcon} title="No tasks reported" description="The engine API returned no gated tasks." />}
              dense
            />
          </Card>

          <div className="space-y-6">
            <Card padded>
              <div className="flex items-start gap-3">
                <InboxStackIcon aria-hidden className="size-6 shrink-0 text-brand-600 dark:text-blue-400" />
                <div className="min-w-0 flex-1">
                  <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Pending approvals</h3>
                  {approvals.error ? <ErrorState className="!px-0 !py-4" error={approvals.error} onRetry={() => approvals.refetch()} />
                    : approvals.isLoading ? <Skeleton className="mt-2 h-8 w-24" />
                    : (
                      <>
                        <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900 dark:text-white">{pendingTotal}</p>
                        <p className="text-sm text-slate-500 dark:text-slate-400">{pendingMine} you can act on</p>
                      </>
                    )}
                  <Link to="/engine/approvals" className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand-600 dark:text-blue-400">Open queue <ArrowRightIcon aria-hidden className="size-4" /></Link>
                </div>
              </div>
            </Card>

            <Card padded>
              <div className="flex items-start gap-3">
                <KeyIcon aria-hidden className="size-6 shrink-0 text-brand-600 dark:text-blue-400" />
                <div className="min-w-0 flex-1">
                  <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Publisher key (Ed25519)</h3>
                  {ov.isLoading ? <Skeleton className="mt-2 h-10 w-full" /> : ov.data ? (
                    <div className="mt-2 space-y-2 text-sm">
                      <div className="flex items-center gap-1">
                        <span className="text-slate-500 dark:text-slate-400">Key id</span>
                        <code className="font-mono text-xs break-all text-slate-900 dark:text-slate-100">{ov.data.publisher_key.key_id}</code>
                        <CopyButton text={ov.data.publisher_key.key_id} label="Copy key id" />
                      </div>
                      <div className="flex items-start gap-1 rounded-md bg-slate-50 p-2 ring-1 ring-slate-200 dark:bg-white/5 dark:ring-white/10">
                        <code className="min-w-0 flex-1 font-mono text-xs break-all text-slate-900 dark:text-slate-100">{ov.data.publisher_key.ed25519_public}</code>
                        <CopyButton text={ov.data.publisher_key.ed25519_public} label="Copy public key" />
                      </div>
                    </div>
                  ) : <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Unavailable.</p>}
                </div>
              </div>
            </Card>
          </div>
        </div>

        <Card>
          <CardHeader title="Evaluations with failing gates" actions={<Link to="/engine/evaluations" className="text-sm font-semibold text-brand-600 dark:text-blue-400">All evaluations</Link>} />
          <DataTable
            columns={failCols}
            rows={evals.data ? failing : undefined}
            rowKey={(e) => e.id}
            loading={evals.isLoading}
            error={evals.error}
            onRetry={() => evals.refetch()}
            onRowClick={(e) => nav(`/engine/evaluations/${e.id}`)}
            empty={<EmptyState icon={BeakerIcon} title="No failing evaluations" description="Every recorded evaluation passed its release gate."
              action={canManage ? <Button icon={PlusIcon} onClick={() => nav('/engine/evaluations?new=1')}>New evaluation</Button> : undefined} />}
          />
        </Card>
      </div>
    </>
  );
}
