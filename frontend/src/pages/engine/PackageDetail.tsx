import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, NoSymbolIcon, TrashIcon } from '@heroicons/react/24/outline';
import { CheckCircleIcon, ExclamationTriangleIcon, ShieldCheckIcon, XCircleIcon } from '@heroicons/react/20/solid';
import {
  Alert, Badge, Button, Card, CardHeader, CodeBlock, ConfirmDialog, DataTable, DescriptionList, EmptyState, ErrorState,
  IconButton, Input, Meter, PageHeader, Skeleton, Textarea, useToast,
} from '../../components/ui';
import { del, get, post } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { bytes, dateTime } from '../../lib/format';
import { uploadWithProgress } from '../SinLama/upload';
import {
  BackLink, CopyButton, DecisionBadge, Digest, LicenceCell, LifecycleStepper, StateBadge, artifactsSize, profileLabel,
  refusalText, stateIcon, stateLabel, taskLabel, useOverview, type Artifact, type PackageDetail as Pkg,
} from './shared';

interface Manifest {
  manifest: unknown; manifest_sha256: string; signature: string; algorithm: string; publisher_key_id: string; verified: boolean;
}

const SIGN_STATES = ['staged', 'active', 'revoked'];

export default function PackageDetail({ id }: { id: string }) {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const canSign = can('engine.sign');
  const toast = useToast();
  const qc = useQueryClient();
  const ov = useOverview();
  const key = ['engine', 'package', id];
  const pkg = useQuery({ queryKey: key, queryFn: () => get<Pkg>(`/engine/packages/${id}`) });
  const signed = !!pkg.data?.manifest_sha256;
  const manifest = useQuery({ queryKey: ['engine', 'manifest', id], queryFn: () => get<Manifest>(`/engine/packages/${id}/manifest`), enabled: signed });

  const [confirmTo, setConfirmTo] = useState<string | null>(null);
  const [revokeOpen, setRevokeOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [removePath, setRemovePath] = useState<string | null>(null);

  const refresh = () => { void qc.invalidateQueries({ queryKey: ['engine'] }); };

  const move = useMutation({
    mutationFn: (v: { to: string; reason?: string }) => post(`/engine/packages/${id}/transition`, v),
    onSuccess: (_d, v) => { toast.success(`Package moved to ${stateLabel(v.to).toLowerCase()}`); setConfirmTo(null); setRevokeOpen(false); setReason(''); refresh(); },
    onError: (e) => { toast.error(refusalText(e)); setConfirmTo(null); },
  });

  const remove = useMutation({
    mutationFn: (path: string) => del(`/engine/packages/${id}/artifacts?path=${encodeURIComponent(path)}`),
    onSuccess: () => { toast.success('Artifact removed'); setRemovePath(null); refresh(); },
    onError: (e) => { toast.error(refusalText(e)); setRemovePath(null); },
  });

  // Upload
  const fileRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [path, setPath] = useState('');
  const [progress, setProgress] = useState<number | null>(null);
  const abort = useRef<AbortController | null>(null);
  const upload = async () => {
    if (!file) return;
    const form = new FormData();
    form.append('file', file);
    if (path.trim()) form.append('path', path.trim());
    abort.current = new AbortController();
    setProgress(0);
    try {
      await uploadWithProgress<Pkg>(`/engine/packages/${id}/artifacts`, form, setProgress, abort.current.signal);
      toast.success('Artifact uploaded', 'The server computed its sha256 and size.');
      setFile(null); setPath(''); if (fileRef.current) fileRef.current.value = '';
      refresh();
    } catch (e) {
      toast.error(refusalText(e));
    } finally {
      setProgress(null);
      abort.current = null;
    }
  };

  if (pkg.error) return <><BackLink to="/engine/packages">Package registry</BackLink><Card><ErrorState error={pkg.error} onRetry={() => pkg.refetch()} /></Card></>;
  if (pkg.isLoading || !pkg.data) return <><BackLink to="/engine/packages">Package registry</BackLink><Skeleton className="mb-4 h-8 w-64" /><Card padded><Skeleton className="h-40 w-full" /></Card></>;
  const p = pkg.data;
  const editable = p.state === 'candidate' || p.state === 'quarantined';
  const next = (p.allowed_next ?? []).filter((s) => s !== 'revoked');
  const history = [...(p.state_history ?? [])].reverse();

  const downloadManifest = () => {
    if (!manifest.data) return;
    const blob = new Blob([JSON.stringify(manifest.data.manifest, null, 2)], { type: 'application/json' });
    const href = URL.createObjectURL(blob);
    Object.assign(document.createElement('a'), { href, download: `${p.package_id}-${p.version}.manifest.json` }).click();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  };

  return (
    <>
      <BackLink to="/engine/packages">Package registry</BackLink>
      <PageHeader
        title={`${taskLabel(p.task)} v${p.version}`}
        meta={<><StateBadge state={p.state} /><span className="font-mono text-xs">{p.package_id}</span></>}
        actions={canSign && p.state !== 'revoked'
          ? <Button variant="danger" icon={NoSymbolIcon} onClick={() => setRevokeOpen(true)}>Revoke</Button> : undefined}
      />

      <div className="space-y-6">
        <Card>
          <CardHeader title="Lifecycle" />
          <div className="space-y-4 p-4 sm:p-6">
            <LifecycleStepper current={p.state} />
            {next.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2">
                {next.map((to) => {
                  const missing = p.missing?.[to] ?? [];
                  const needsSign = SIGN_STATES.includes(to);
                  const allowed = needsSign ? canSign : canManage;
                  const I = stateIcon(to);
                  const viaBindings = to === 'active';
                  return (
                    <div key={to} className="rounded-md p-3 ring-1 ring-slate-200 dark:ring-white/10">
                      {viaBindings ? (
                        <Link to="/engine/bindings" className="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-500">
                          <I aria-hidden className="size-4" /> Activate in bindings
                        </Link>
                      ) : (
                        <Button variant="primary" icon={I} disabled={!allowed || missing.length > 0} loading={move.isPending && move.variables?.to === to}
                          onClick={() => (to === 'staged' || to === 'retired' ? setConfirmTo(to) : move.mutate({ to }))}>
                          {to === 'staged' ? 'Sign and stage' : `Move to ${stateLabel(to).toLowerCase()}`}
                        </Button>
                      )}
                      {!allowed && <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Needs the {needsSign ? 'engine.sign' : 'engine.manage'} permission.</p>}
                      {missing.length > 0 && (
                        <div className="mt-2 text-xs text-amber-800 dark:text-amber-300">
                          <p className="flex items-center gap-1 font-semibold"><ExclamationTriangleIcon aria-hidden className="size-4" /> Missing evidence</p>
                          <ul className="mt-1 list-disc space-y-0.5 pl-5">{missing.map((m) => <li key={m}>{m}</li>)}</ul>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            ) : <p className="text-sm text-slate-500 dark:text-slate-400">No further steps from {stateLabel(p.state).toLowerCase()}.</p>}
          </div>
        </Card>

        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardHeader title="Artifacts" description={`${p.artifacts?.length ?? 0} files · ${bytes(artifactsSize(p.artifacts))}`} />
            {canManage && editable && (
              <div className="space-y-3 border-b border-slate-200 p-4 sm:px-6 dark:border-white/10">
                <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                  <div>
                    <label className="block text-sm/6 font-medium text-slate-900 dark:text-white" htmlFor="art-file">File</label>
                    <input id="art-file" ref={fileRef} type="file" disabled={progress !== null}
                      onChange={(e) => { const f = e.target.files?.[0] ?? null; setFile(f); if (f && !path) setPath(f.name); }}
                      className="mt-1.5 block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-900 hover:file:bg-slate-200 dark:text-slate-300 dark:file:bg-white/10 dark:file:text-white" />
                  </div>
                  <Input label="Path in package" placeholder="model/weights.gguf" value={path} onChange={(e) => setPath(e.target.value)} disabled={progress !== null} />
                  {progress === null
                    ? <Button variant="primary" icon={ArrowUpTrayIcon} disabled={!file} onClick={() => void upload()}>Upload</Button>
                    : <Button onClick={() => abort.current?.abort()}>Cancel</Button>}
                </div>
                {progress !== null && <Meter value={progress * 100} label={`Uploading ${file?.name ?? ''}`} />}
                <p className="text-xs text-slate-500 dark:text-slate-400">An artifact with the same path is replaced. Paths may not escape the package or be executables.</p>
              </div>
            )}
            {!editable && <p className="border-b border-slate-200 px-4 py-2 text-xs text-slate-500 sm:px-6 dark:border-white/10 dark:text-slate-400">Artifacts can only change while the package is a candidate or quarantined.</p>}
            <DataTable<Artifact>
              rows={p.artifacts ?? []}
              rowKey={(a) => a.path}
              empty={<EmptyState icon={ArrowUpTrayIcon} title="No artifacts yet" description={editable ? 'Upload the package files above; the server hashes each one.' : 'This package has no artifacts.'} />}
              columns={[
                { key: 'path', header: 'Path', cell: (a) => <span className="font-mono text-xs break-all">{a.path}</span> },
                { key: 'sha', header: 'sha256', hideBelow: 'sm', cell: (a) => <Digest value={a.sha256} /> },
                { key: 'size', header: 'Size', align: 'right', cell: (a) => <span className="tabular-nums">{bytes(a.size_bytes)}</span> },
                { key: 'ver', header: 'Hash source', hideBelow: 'md', cell: (a) => a.server_verified
                  ? <Badge tone="green"><ShieldCheckIcon aria-hidden className="-ml-0.5 size-3.5" />Server-verified</Badge>
                  : <Badge tone="gray"><ExclamationTriangleIcon aria-hidden className="-ml-0.5 size-3.5" />Declared</Badge> },
                { key: 'x', header: <span className="sr-only">Actions</span>, align: 'right', cell: (a) => (canManage && editable
                  ? <IconButton label={`Remove ${a.path}`} icon={TrashIcon} variant="ghost" size="sm" onClick={() => setRemovePath(a.path)} /> : null) },
              ]}
            />
          </Card>

          <Card>
            <CardHeader title="Details" />
            <DescriptionList items={[
              { label: 'Runtime', value: `${p.runtime_name} ${p.runtime_range}` },
              { label: 'Languages', value: p.languages || '—' },
              { label: 'Base model', value: p.base_model || '—' },
              { label: 'Quantization', value: p.quantization || '—' },
              { label: 'Licence', value: <LicenceCell id={p.license_id} blocked={ov.data?.blocked_licenses ?? []} /> },
              { label: 'Rights approval', value: p.rights_approval_id || '—' },
              { label: 'Evaluation', value: p.evaluation ? <Link to={`/engine/evaluations/${p.evaluation.id}`} className="inline-flex items-center gap-2 text-brand-600 hover:underline dark:text-blue-400">#{p.evaluation.id} <DecisionBadge decision={p.evaluation.decision} /></Link> : <DecisionBadge decision={null} /> },
              { label: 'Bindings', value: p.bindings?.length ? p.bindings.map((b) => `${profileLabel(b.profile)} (${b.role})`).join(', ') : 'Not bound' },
            ]} />
          </Card>
        </div>

        <Card>
          <CardHeader title="Manifest" actions={manifest.data ? <Button size="sm" icon={ArrowDownTrayIcon} onClick={downloadManifest}>Download manifest JSON</Button> : undefined} />
          {!signed ? <EmptyState title="Not signed yet" description="The manifest is generated and signed when the package moves from approved to staged." />
            : manifest.error ? <ErrorState error={manifest.error} onRetry={() => manifest.refetch()} />
            : manifest.isLoading || !manifest.data ? <div className="p-6"><Skeleton className="h-32 w-full" /></div>
            : (
              <div className="space-y-4 p-4 sm:p-6">
                {manifest.data.verified
                  ? <Alert tone="success"><span className="inline-flex items-center gap-1.5 font-semibold"><CheckCircleIcon aria-hidden className="size-4" /> Signature verified</span> — {manifest.data.algorithm} with key {manifest.data.publisher_key_id}.</Alert>
                  : <Alert tone="danger"><span className="inline-flex items-center gap-1.5 font-semibold"><XCircleIcon aria-hidden className="size-4" /> Signature does not verify</span> — do not ship this package.</Alert>}
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="text-sm"><div className="text-xs text-slate-500 dark:text-slate-400">Manifest sha256</div><Digest value={manifest.data.manifest_sha256} n={24} /></div>
                  <div className="text-sm"><div className="text-xs text-slate-500 dark:text-slate-400">Signature</div>
                    <span className="inline-flex items-center gap-0.5"><code className="font-mono text-xs break-all">{manifest.data.signature.slice(0, 24)}…</code><CopyButton text={manifest.data.signature} label="Copy signature" /></span>
                  </div>
                </div>
                <CodeBlock className="max-h-96 overflow-auto">{JSON.stringify(manifest.data.manifest, null, 2)}</CodeBlock>
              </div>
            )}
        </Card>

        <Card>
          <CardHeader title="History" />
          {history.length ? (
            <ol className="space-y-4 p-4 sm:p-6">
              {history.map((h, i) => {
                const I = stateIcon(h.to);
                return (
                  <li key={i} className="flex gap-3">
                    <I aria-hidden className="mt-0.5 size-5 shrink-0 text-slate-400 dark:text-slate-500" />
                    <div className="min-w-0 text-sm">
                      <p className="text-slate-900 dark:text-white">{h.from ? `${stateLabel(h.from)} → ` : ''}<span className="font-semibold">{stateLabel(h.to)}</span></p>
                      <p className="text-xs text-slate-500 dark:text-slate-400">{dateTime(h.at)}{h.by_name ? ` · ${h.by_name}` : ''}</p>
                      {h.reason && <p className="mt-1 text-xs text-slate-700 dark:text-slate-300">Reason: {h.reason}</p>}
                    </div>
                  </li>
                );
              })}
            </ol>
          ) : <EmptyState title="No history recorded" />}
        </Card>
      </div>

      <ConfirmDialog
        open={!!confirmTo}
        onClose={() => setConfirmTo(null)}
        onConfirm={() => confirmTo && move.mutate({ to: confirmTo })}
        busy={move.isPending}
        tone={confirmTo === 'retired' ? 'danger' : 'primary'}
        confirmLabel={confirmTo === 'staged' ? 'Sign and stage' : 'Retire'}
        title={confirmTo === 'staged' ? 'Sign this package?' : 'Retire this package?'}
        message={confirmTo === 'staged'
          ? 'The manifest is generated from the current artifacts and signed with the publisher key. A signed manifest is never edited; only revocation withdraws it.'
          : 'A retired package can no longer be activated.'}
      />

      <ConfirmDialog
        open={revokeOpen}
        onClose={() => setRevokeOpen(false)}
        onConfirm={() => { if (reason.trim()) move.mutate({ to: 'revoked', reason: reason.trim() }); else toast.error('A reason is required to revoke.'); }}
        busy={move.isPending}
        confirmLabel="Revoke package"
        title={`Revoke ${taskLabel(p.task)} v${p.version}?`}
        message={
          <div className="space-y-3">
            <p>Clients refuse a revoked package, and it cannot be un-revoked.{p.bindings?.length ? ' It is currently bound — roll back or activate another package first.' : ''}</p>
            <Textarea aria-label="Reason" required rows={3} placeholder="Reason (required)" value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
        }
      />

      <ConfirmDialog
        open={!!removePath}
        onClose={() => setRemovePath(null)}
        onConfirm={() => removePath && remove.mutate(removePath)}
        busy={remove.isPending}
        confirmLabel="Remove"
        title="Remove this artifact?"
        message={<>The stored file <code className="font-mono">{removePath}</code> is deleted.</>}
      />
    </>
  );
}
