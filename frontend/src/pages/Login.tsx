import { useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/20/solid';
import { useAuth } from '../lib/auth';
import { ApiError } from '../lib/api';
import { Alert, Button, Input } from '../components/ui';
import { Logo } from '../layout/Logo';

export default function Login() {
  const { user, signIn } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const from = (location.state as { from?: string } | null)?.from ?? '/';

  if (user) return <Navigate to={from} replace />;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null); setFields({});
    try {
      await signIn(email.trim(), password);
      navigate(from, { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign-in failed.');
      if (err instanceof ApiError) setFields(err.fields);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-full">
      <div className="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
        <div className="mx-auto w-full max-w-sm lg:w-96">
          <Logo />
          <h1 className="mt-8 text-2xl/9 font-bold tracking-tight text-slate-900 dark:text-white">Sign in to AI Studio</h1>
          <p className="mt-2 text-sm/6 text-slate-500 dark:text-slate-400">Manage your models, nodes, gateway and teams.</p>
          <form onSubmit={submit} className="mt-8 space-y-5" noValidate>
            {error && <Alert tone="danger">{error}</Alert>}
            <Input label="Email address" type="email" autoComplete="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} error={fields.email} />
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
            <Button type="submit" variant="primary" size="lg" loading={busy} className="w-full">Sign in</Button>
          </form>
          <p className="mt-10 text-xs text-slate-400">© 2026 Livezen Technologies</p>
        </div>
      </div>
      <div className="relative hidden w-0 flex-1 overflow-hidden lg:block">
        <div className="absolute inset-0 bg-gradient-to-br from-blue-600 via-blue-700 to-slate-900" />
        <div className="absolute inset-0 opacity-20 [background-image:radial-gradient(circle_at_1px_1px,white_1px,transparent_0)] [background-size:24px_24px]" />
        <div className="relative flex h-full flex-col justify-end p-16 text-white">
          <p className="text-sm font-semibold tracking-wide text-blue-200 uppercase">WorkSuite AI Studio</p>
          <p className="mt-3 max-w-md text-3xl/tight font-semibold">Your models, nodes and AI gateway — in one control plane.</p>
          <ul className="mt-6 space-y-2 text-sm text-blue-100">
            <li>• Run and monitor Ollama nodes</li>
            <li>• Build SinLama models, datasets and training kits</li>
            <li>• Issue gateway keys and watch every call</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
