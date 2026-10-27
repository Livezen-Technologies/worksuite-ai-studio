import { useState } from 'react';
import { Link as RouterLink, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { GradientBackground } from '../site/ui';
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/20/solid';
import { FingerPrintIcon } from '@heroicons/react/24/outline';
import { useAuth, type Session } from '../lib/auth';
import { ApiError, post } from '../lib/api';
import { passkeysSupported } from '../lib/webauthn';
import { Alert, Button, Input } from '../components/ui';
import { RecoveryCodes, TotpEnrol } from '../components/security';
import { Logo } from '../layout/Logo';

/** password → (mfa | setup → codes) → signed in. */
type Step =
  | { kind: 'password' }
  | { kind: 'mfa'; mfaToken: string; recovery: boolean }
  | { kind: 'setup'; mfaToken: string; secret: string; uri: string; setupToken: string }
  | { kind: 'codes'; session: Session };

type Busy = 'password' | 'passkey' | 'code' | null;

export default function Login() {
  const { user, signIn, signInWithPasskey, completeSession } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [code, setCode] = useState('');
  const [step, setStep] = useState<Step>({ kind: 'password' });
  const [busy, setBusy] = useState<Busy>(null);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const from = (location.state as { from?: string } | null)?.from ?? '/';

  if (user) return <Navigate to={from} replace />;

  const run = async (which: Exclude<Busy, null>, fn: () => Promise<void>) => {
    setBusy(which); setError(null); setFields({});
    try {
      await fn();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign-in failed.');
      if (err instanceof ApiError) {
        setFields(err.fields);
        // An expired 2FA step token sends them back to the password form.
        if (err.status === 401 && step.kind !== 'password') { setStep({ kind: 'password' }); setCode(''); }
      }
    } finally {
      setBusy(null);
    }
  };

  const submitPassword = (e: React.FormEvent) => {
    e.preventDefault();
    run('password', async () => {
      const r = await signIn(email.trim(), password);
      setCode('');
      if (r.step === 'done') navigate(from, { replace: true });
      else if (r.step === 'mfa') setStep({ kind: 'mfa', mfaToken: r.mfaToken, recovery: false });
      else {
        const d = await post<{ secret: string; uri: string; setup_token: string }>('/auth/mfa/setup', { mfa_token: r.mfaToken });
        setStep({ kind: 'setup', mfaToken: r.mfaToken, secret: d.secret, uri: d.uri, setupToken: d.setup_token });
      }
    });
  };

  const submitCode = (e: React.FormEvent) => {
    e.preventDefault();
    run('code', async () => {
      if (step.kind === 'mfa') {
        completeSession(await post<Session>('/auth/mfa', { mfa_token: step.mfaToken, code }));
        navigate(from, { replace: true });
      } else if (step.kind === 'setup') {
        setStep({ kind: 'codes', session: await post<Session>('/auth/mfa/setup/confirm', { mfa_token: step.mfaToken, setup_token: step.setupToken, code }) });
      }
    });
  };

  const passkey = () => run('passkey', async () => { await signInWithPasskey(); navigate(from, { replace: true }); });
  const back = () => { setStep({ kind: 'password' }); setCode(''); setError(null); setFields({}); };

  const heading = {
    password: 'Sign in to AI Studio',
    mfa: 'Two-factor authentication',
    setup: 'Set up two-factor authentication',
    codes: 'Save your recovery codes',
  }[step.kind];
  const sub = {
    password: 'Manage your models, nodes, gateway and teams.',
    mfa: step.kind === 'mfa' && step.recovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.',
    setup: 'Your administrator requires a second step when you sign in.',
    codes: 'Two-factor authentication is on.',
  }[step.kind];

  return (
    // Radiant sign-in: a card on the site's gradient. The forms inside are the app's (password, passkey, 2FA, codes).
    <main className="min-h-full overflow-hidden bg-gray-50 font-[Switzer,_ui-sans-serif,_system-ui,_sans-serif] dark:bg-slate-950">
      <GradientBackground />
      <div className="isolate flex min-h-dvh items-center justify-center p-6 lg:p-8">
        <div className="w-full max-w-md rounded-xl bg-white shadow-md ring-1 ring-black/5 dark:bg-slate-900 dark:ring-white/10">
        <div className="p-7 sm:p-11">
          <RouterLink to="/" title="AI Studio home" className="inline-flex"><Logo /></RouterLink>
          <h1 className="mt-8 text-base/6 font-medium text-slate-900 dark:text-white">{heading}</h1>
          <p className="mt-2 text-sm/6 text-slate-500 dark:text-slate-400">{sub}</p>

          {step.kind === 'password' && (
            <>
              <form onSubmit={submitPassword} className="mt-8 space-y-5" noValidate>
                {error && <Alert tone="danger">{error}</Alert>}
                <Input label="Email address" type="email" autoComplete="username webauthn" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} error={fields.email} />
                <Input
                  label="Password"
                  type={show ? 'text' : 'password'}
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  error={fields.password}
                  trailing={
                    <button type="button" onClick={() => setShow((s) => !s)} className="text-slate-400 hover:text-slate-600" aria-label={show ? 'Hide password' : 'Show password'}>
                      {show ? <EyeSlashIcon className="size-5" /> : <EyeIcon className="size-5" />}
                    </button>
                  }
                />
                <Button type="submit" variant="primary" size="lg" loading={busy === 'password'} disabled={busy !== null} className="w-full">Sign in</Button>
              </form>
              {passkeysSupported() && (
                <>
                  <div className="my-6 flex items-center gap-3 text-xs text-slate-400">
                    <span className="h-px flex-1 bg-slate-200 dark:bg-white/10" /> or <span className="h-px flex-1 bg-slate-200 dark:bg-white/10" />
                  </div>
                  <Button size="lg" icon={FingerPrintIcon} loading={busy === 'passkey'} disabled={busy !== null} onClick={passkey} className="w-full">Sign in with a passkey</Button>
                </>
              )}
            </>
          )}

          {step.kind === 'mfa' && (
            <form onSubmit={submitCode} className="mt-8 space-y-5" noValidate>
              {error && <Alert tone="danger">{error}</Alert>}
              {step.recovery ? (
                <Input key="recovery" label="Recovery code" autoComplete="off" placeholder="xxxxx-xxxxx" required autoFocus value={code} onChange={(e) => setCode(e.target.value)} error={fields.code} />
              ) : (
                <Input key="totp" label="Authentication code" inputMode="numeric" autoComplete="one-time-code" maxLength={7} required autoFocus value={code} onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))} error={fields.code} />
              )}
              <Button type="submit" variant="primary" size="lg" loading={busy === 'code'} className="w-full">Verify</Button>
              <div className="flex justify-between text-sm">
                <button type="button" onClick={back} className="text-slate-500 hover:text-slate-700 dark:text-slate-400">Back</button>
                <button type="button" onClick={() => { setStep({ ...step, recovery: !step.recovery }); setCode(''); setError(null); }} className="font-medium text-brand-600 hover:underline dark:text-blue-400">
                  {step.recovery ? 'Use authenticator code' : 'Use a recovery code'}
                </button>
              </div>
            </form>
          )}

          {step.kind === 'setup' && (
            <form onSubmit={submitCode} className="mt-8 space-y-5" noValidate>
              {error && <Alert tone="danger">{error}</Alert>}
              <TotpEnrol secret={step.secret} uri={step.uri} code={code} onCode={setCode} error={fields.code} />
              <Button type="submit" variant="primary" size="lg" loading={busy === 'code'} className="w-full">Turn on and continue</Button>
              <button type="button" onClick={back} className="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">Back</button>
            </form>
          )}

          {step.kind === 'codes' && (
            <div className="mt-8 space-y-5">
              <RecoveryCodes codes={step.session.recovery_codes ?? []} />
              <Button variant="primary" size="lg" className="w-full" onClick={() => { completeSession(step.session); navigate(from, { replace: true }); }}>I've saved them — continue</Button>
            </div>
          )}

        </div>
        <div className="m-1.5 flex items-center justify-between rounded-lg bg-gray-50 px-5 py-4 text-sm/5 ring-1 ring-black/5 dark:bg-white/5 dark:ring-white/10">
          <span className="text-slate-500 dark:text-slate-400">© 2026 Livezen Technologies</span>
          <RouterLink to="/" className="font-medium text-slate-900 hover:text-slate-700 dark:text-white">About AI Studio</RouterLink>
        </div>
        </div>
      </div>
    </main>
  );
}
