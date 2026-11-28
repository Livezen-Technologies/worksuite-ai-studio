/**
 * Auto sign-in with WorkSuite Cloud (staff SSO "auto sign-in"): when WSC is configured and nobody is
 * signed in, the sign-in page tries the silent flow (prompt=none) once per browser tab. It is skipped
 * after an explicit sign-out (until the next manual sign-in), with ?local=1, or when the page is
 * already showing a WSC result/error. Storage access is wrapped: a blocked storage just means "no auto".
 */
export const TRIED_KEY = 'aistudio.wsc.autoTried';
export const SIGNED_OUT_KEY = 'aistudio.wsc.signedOut';

type Store = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

function safe<T>(fn: () => T, fallback: T): T {
  try { return fn(); } catch { return fallback; }
}

export function shouldAutoSignIn(
  opts: { enabled: boolean; signedIn: boolean; params: URLSearchParams },
  tab: Store | null = safe(() => window.sessionStorage, null),
  device: Store | null = safe(() => window.localStorage, null),
): boolean {
  const { enabled, signedIn, params } = opts;
  if (!enabled || signedIn || !tab) return false;
  if (params.get('local') === '1' || params.has('wsc') || params.has('wsc_error')) return false;
  if (safe(() => device?.getItem(SIGNED_OUT_KEY), null) === '1') return false;
  if (safe(() => tab.getItem(TRIED_KEY), null) === '1') return false;
  // Mark before redirecting, so coming back with login_required never loops.
  return safe(() => { tab.setItem(TRIED_KEY, '1'); return true; }, false);
}

/** Explicit sign-out from AI Studio: no auto sign-in until the next manual sign-in. */
export function markSignedOut(device: Store | null = safe(() => window.localStorage, null)): void {
  safe(() => device?.setItem(SIGNED_OUT_KEY, '1'), undefined);
}

/** A manual sign-in (password, passkey or the WSC button) turns auto sign-in back on. */
export function clearSignedOut(device: Store | null = safe(() => window.localStorage, null)): void {
  safe(() => device?.removeItem(SIGNED_OUT_KEY), undefined);
}

/** WSC_SSO_POLICY as the server reports it (GET /api/auth/wsc/config). */
export type SsoPolicy = 'local' | 'oneauth_optional' | 'oneauth_preferred' | 'oneauth_required';

/**
 * Whether the sign-in page leads with "Sign in with WorkSuite Cloud" and keeps the password form behind
 * "Use a password instead" (oneauth_preferred / oneauth_required). ?local=1 shows the form straight away.
 */
export function leadsWithWsc(opts: { enabled: boolean; policy?: string; params: URLSearchParams }): boolean {
  const { enabled, policy, params } = opts;
  if (!enabled || params.get('local') === '1') return false;
  return policy === 'oneauth_preferred' || policy === 'oneauth_required';
}
