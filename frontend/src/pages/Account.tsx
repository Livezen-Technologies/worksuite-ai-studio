import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FingerPrintIcon, KeyIcon, PencilSquareIcon, PlusIcon, ShieldCheckIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorState, FormModal, Input, Modal, PageHeader, Skeleton, useToast,
} from '../components/ui';
import { RecoveryCodes, TotpEnrol } from '../components/security';
import { ApiError, del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, dateTime } from '../lib/format';
import { createPasskey, passkeysSupported, type CreationOptionsJSON } from '../lib/webauthn';

interface Passkey { id: number; name: string; transports: string | null; created_at: string | null; last_used_at: string | null }
interface SecurityState {
  two_factor_enabled: boolean; two_factor_since: string | null; two_factor_required: boolean; recovery_codes_left: number; passkeys: Passkey[];
}

const KEY = ['account-security'];

/** Account → Security: the signed-in user's passkeys and two-factor authentication. */
export default function Account() {
  const { user } = useAuth();
  const q = useQuery({ queryKey: KEY, queryFn: () => get<SecurityState>('/account/security') });

  return (
    <>
      <PageHeader title="Account security" description={`How ${user?.email ?? 'you'} signs in to AI Studio.`} />
      {q.error ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : !q.data ? (
        <div className="space-y-6"><Skeleton className="h-40" /><Skeleton className="h-40" /></div>
      ) : (
        <div className="space-y-6">
          <TwoFactorCard s={q.data} />
          <PasskeysCard passkeys={q.data.passkeys} />
        </div>
      )}
    </>
  );
}

function TwoFactorCard({ s }: { s: SecurityState }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { refreshProfile } = useAuth();
  const [setup, setSetup] = useState<{ secret: string; uri: string; setup_token: string } | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [pwFor, setPwFor] = useState<'disable' | 'codes' | null>(null);
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const done = () => { qc.invalidateQueries({ queryKey: KEY }); refreshProfile().catch(() => {}); };
  const onErr = (e: unknown) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e instanceof Error ? e.message : 'Failed'); };

  const start = useMutation({
    mutationFn: () => post<{ secret: string; uri: string; setup_token: string }>('/account/security/totp/setup'),
    onSuccess: (d) => { setSetup(d); setCode(''); setErrors({}); },
    onError: onErr,
  });
  const enable = useMutation({
    mutationFn: () => post<{ recovery_codes: string[] }>('/account/security/totp/enable', { setup_token: setup?.setup_token, code }),
    onSuccess: (d) => { setSetup(null); setCodes(d.recovery_codes); done(); },
    onError: onErr,
  });
  const withPassword = useMutation({
    mutationFn: () => pwFor === 'disable'
      ? post<null>('/account/security/totp/disable', { password })
      : post<{ recovery_codes: string[] }>('/account/security/recovery-codes', { password }),
    onSuccess: (d) => {
      if (d && 'recovery_codes' in d) setCodes(d.recovery_codes);
      else toast.success('Two-factor authentication is off');
      setPwFor(null); setPassword(''); done();
    },
    onError: onErr,
  });

  return (
    <Card>
      <CardHeader
        title={<span className="flex items-center gap-2"><ShieldCheckIcon aria-hidden className="size-5 text-slate-400" /> Two-factor authentication</span>}
        description="Ask for a code from an authenticator app after your password."
        actions={s.two_factor_enabled ? <Badge tone="green" dot>On</Badge> : <Badge tone="gray" dot>Off</Badge>}
      />
      <div className="space-y-4 px-4 py-5 sm:px-6">
        {s.two_factor_required && !s.two_factor_enabled && <Alert tone="warning">Your administrator requires two-factor authentication. Turn it on now.</Alert>}
        {s.two_factor_enabled ? (
          <>
            <p className="text-sm text-slate-600 dark:text-slate-300">
              On since {dateTime(s.two_factor_since)}. {s.recovery_codes_left} recovery code{s.recovery_codes_left === 1 ? '' : 's'} left.
              {s.two_factor_required && ' Your administrator requires it, so it cannot be turned off.'}
            </p>
            {s.recovery_codes_left <= 3 && <Alert tone="warning">You are running low on recovery codes. Create new ones.</Alert>}
            <div className="flex flex-wrap gap-2">
              <Button icon={KeyIcon} onClick={() => { setPwFor('codes'); setErrors({}); }}>New recovery codes</Button>
              {!s.two_factor_required && <Button variant="danger" onClick={() => { setPwFor('disable'); setErrors({}); }}>Turn off</Button>}
            </div>
          </>
        ) : (
          <Button variant="primary" loading={start.isPending} onClick={() => start.mutate()}>Turn on two-factor authentication</Button>
        )}
      </div>

      <FormModal open={setup !== null} onClose={() => setSetup(null)} title="Add an authenticator app" onSubmit={() => enable.mutate()} submitLabel="Turn on" submitting={enable.isPending}>
        {setup && <TotpEnrol secret={setup.secret} uri={setup.uri} code={code} onCode={setCode} error={errors.code} />}
      </FormModal>

      <Modal open={codes !== null} onClose={() => setCodes(null)} title="Your recovery codes" footer={<Button variant="primary" onClick={() => setCodes(null)}>I've saved them</Button>}>
        {codes && <RecoveryCodes codes={codes} />}
      </Modal>

      <FormModal
        open={pwFor !== null}
        onClose={() => { setPwFor(null); setPassword(''); }}
        title={pwFor === 'disable' ? 'Turn off two-factor authentication' : 'Create new recovery codes'}
        description={pwFor === 'disable' ? 'Your account will be protected by your password only.' : 'Your old codes will stop working.'}
        onSubmit={() => withPassword.mutate()}
        submitLabel={pwFor === 'disable' ? 'Turn off' : 'Create codes'}
        submitting={withPassword.isPending}
        danger={pwFor === 'disable'}
      >
        <Input label="Confirm your password" type="password" autoComplete="current-password" required autoFocus value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} />
      </FormModal>
    </Card>
  );
}

function PasskeysCard({ passkeys }: { passkeys: Passkey[] }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const [renaming, setRenaming] = useState<Passkey | null>(null);
  const [removing, setRemoving] = useState<Passkey | null>(null);
  const done = () => qc.invalidateQueries({ queryKey: KEY });
  const onErr = (e: unknown) => toast.error(e instanceof Error ? e.message : 'Failed');

  const add = useMutation({
    mutationFn: async () => {
      const { options, challenge_token } = await post<{ options: CreationOptionsJSON; challenge_token: string }>('/account/security/passkeys/options');
      const credential = await createPasskey(options);
      return post('/account/security/passkeys', { challenge_token, credential, name: name.trim() });
    },
    onSuccess: () => { toast.success('Passkey added'); setAdding(false); setName(''); done(); },
    onError: onErr,
  });
  const rename = useMutation({
    mutationFn: () => put(`/account/security/passkeys/${renaming?.id}`, { name: name.trim() }),
    onSuccess: () => { toast.success('Passkey renamed'); setRenaming(null); done(); },
    onError: onErr,
  });
  const remove = useMutation({
    mutationFn: () => del(`/account/security/passkeys/${removing?.id}`),
    onSuccess: () => { toast.success('Passkey removed'); setRemoving(null); done(); },
    onError: onErr,
  });

  return (
    <Card>
      <CardHeader
        title={<span className="flex items-center gap-2"><FingerPrintIcon aria-hidden className="size-5 text-slate-400" /> Passkeys</span>}
        description="Sign in with your fingerprint, face or device PIN instead of a password and code."
        actions={passkeysSupported() && <Button size="sm" icon={PlusIcon} onClick={() => { setName(defaultName()); setAdding(true); }}>Add passkey</Button>}
      />
      {!passkeysSupported() && <Alert tone="warning" className="m-4">This browser does not support passkeys.</Alert>}
      {passkeys.length === 0 ? (
        <EmptyState icon={FingerPrintIcon} title="No passkeys yet" description="Add one on each device you use to sign in." />
      ) : (
        <ul role="list" className="divide-y divide-slate-100 dark:divide-white/5">
          {passkeys.map((p) => (
            <li key={p.id} className="flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{p.name}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">Added {dateTime(p.created_at)} · {p.last_used_at ? `last used ${ago(p.last_used_at)}` : 'never used'}</p>
              </div>
              <ActionMenu actions={[
                { label: 'Rename', icon: PencilSquareIcon, onClick: () => { setName(p.name); setRenaming(p); } },
                { label: 'Remove', icon: TrashIcon, onClick: () => setRemoving(p), danger: true },
              ]} />
            </li>
          ))}
        </ul>
      )}

      <FormModal open={adding} onClose={() => setAdding(false)} title="Add a passkey" description="Your browser will ask you to confirm with your fingerprint, face or PIN." onSubmit={() => add.mutate()} submitLabel="Continue" submitting={add.isPending}>
        <Input label="Name" hint="So you can tell your passkeys apart." required autoFocus value={name} onChange={(e) => setName(e.target.value)} />
      </FormModal>
      <FormModal open={renaming !== null} onClose={() => setRenaming(null)} title="Rename passkey" onSubmit={() => rename.mutate()} submitting={rename.isPending}>
        <Input label="Name" required autoFocus value={name} onChange={(e) => setName(e.target.value)} />
      </FormModal>
      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        onConfirm={() => remove.mutate()}
        title="Remove passkey"
        message={`"${removing?.name}" will no longer sign you in. Also remove it from the device's password manager.`}
        confirmLabel="Remove"
        busy={remove.isPending}
      />
    </Card>
  );
}

function defaultName(): string {
  const ua = navigator.userAgent;
  const os = /iPhone|iPad/.test(ua) ? 'iPhone' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'This device';
  const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
  return browser ? `${os} · ${browser}` : os;
}
