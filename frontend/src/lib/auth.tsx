import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, setSessionExpiredHandler, tokens } from './api';

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

interface AuthState {
  user: User | null;
  loading: boolean;
  signIn: (email: string, password: string) => Promise<void>;
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

  const signIn = useCallback(async (email: string, password: string) => {
    const d = await api<{ user: User; access_token: string; refresh_token: string }>('/auth/login', {
      method: 'POST',
      body: { email, password },
    });
    tokens.set(d.access_token, d.refresh_token);
    setUser(d.user);
  }, []);

  const can = useCallback(
    (p: string) => {
      if (!user) return false;
      if (user.role === 'super_admin' || user.role === 'admin') return true;
      return (user.permissions ?? []).includes(p);
    },
    [user],
  );

  const value = useMemo(() => ({ user, loading, signIn, signOut, can, refreshProfile }), [user, loading, signIn, signOut, can, refreshProfile]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAuth() {
  const v = useContext(Ctx);
  if (!v) throw new Error('useAuth outside AuthProvider');
  return v;
}
