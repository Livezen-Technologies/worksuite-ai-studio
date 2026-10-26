import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, setSessionExpiredHandler, tokens } from './api';
import { getPasskey, type RequestOptionsJSON } from './webauthn';

export interface User {
  id: number;
  name: string;
  email: string;
  role?: string;
  role_name?: string;
  permissions?: string[];
  avatar?: string | null;
  [k: string]: unknown;
}

/** What /auth/login, /auth/mfa, /auth/passkey… answer once sign-in is complete. */
export interface Session { user: User; access_token: string; refresh_token: string; recovery_codes?: string[] }

/** A password sign-in either finishes, or needs a 2FA code, or (2FA enforced) needs an authenticator set up first. */
export type SignInResult =
  | { step: 'done' }
  | { step: 'mfa'; mfaToken: string }
  | { step: 'mfa_setup'; mfaToken: string };

interface AuthState {
  user: User | null;
  loading: boolean;
  signIn: (email: string, password: string) => Promise<SignInResult>;
  signInWithPasskey: () => Promise<void>;
  /** Store a session returned by a later sign-in step (2FA code, enforced setup). */
  completeSession: (s: Session) => void;
  signOut: () => void;
  can: (permission: string) => boolean;
  refreshProfile: () => Promise<void>;
}

const Ctx = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(Boolean(tokens.access || tokens.refresh));

  const signOut = useCallback(() => {
    if (tokens.access) api('/auth/logout', { method: 'POST' }).catch(() => {});
    tokens.clear();
    setUser(null);
  }, []);

  const refreshProfile = useCallback(async () => {
    const d = await api<{ user: User }>('/auth/profile');
    setUser(d.user);
  }, []);

  useEffect(() => {
    setSessionExpiredHandler(() => { tokens.clear(); setUser(null); });
    if (!tokens.access && !tokens.refresh) return;
    refreshProfile().catch(() => tokens.clear()).finally(() => setLoading(false));
  }, [refreshProfile]);

  const completeSession = useCallback((s: Session) => {
    tokens.set(s.access_token, s.refresh_token);
    setUser(s.user);
  }, []);

  const signIn = useCallback(async (email: string, password: string): Promise<SignInResult> => {
    const d = await api<Session & { mfa_required?: boolean; mfa_setup_required?: boolean; mfa_token?: string }>('/auth/login', {
      method: 'POST',
      body: { email, password },
    });
    if (d.mfa_required && d.mfa_token) return { step: 'mfa', mfaToken: d.mfa_token };
    if (d.mfa_setup_required && d.mfa_token) return { step: 'mfa_setup', mfaToken: d.mfa_token };
    completeSession(d);
    return { step: 'done' };
  }, [completeSession]);

  const signInWithPasskey = useCallback(async () => {
    const { options, challenge_token } = await api<{ options: RequestOptionsJSON; challenge_token: string }>('/auth/passkey/options', { method: 'POST', body: {} });
    const credential = await getPasskey(options);
    completeSession(await api<Session>('/auth/passkey', { method: 'POST', body: { challenge_token, credential } }));
  }, [completeSession]);

  const can = useCallback(
    (p: string) => {
      if (!user) return false;
      if (user.role === 'super_admin' || user.role === 'admin') return true;
      const perms = user.permissions ?? [];
      if (perms.includes(p)) return true;
      // The engine API falls back to models.* for roles created before engine.* existed (EngineController::guard).
      if (p === 'engine.view') return perms.includes('models.view');
      if (p === 'engine.manage') return perms.includes('models.manage');
      return false;
    },
    [user],
  );

  const value = useMemo(
    () => ({ user, loading, signIn, signInWithPasskey, completeSession, signOut, can, refreshProfile }),
    [user, loading, signIn, signInWithPasskey, completeSession, signOut, can, refreshProfile],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAuth() {
  const v = useContext(Ctx);
  if (!v) throw new Error('useAuth outside AuthProvider');
  return v;
}
