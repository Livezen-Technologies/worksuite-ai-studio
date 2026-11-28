import { describe, expect, it } from 'vitest';
import { SIGNED_OUT_KEY, TRIED_KEY, clearSignedOut, leadsWithWsc, markSignedOut, shouldAutoSignIn } from './wscAuto';

function store(): Storage {
  const m = new Map<string, string>();
  return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => void m.set(k, v), removeItem: (k) => void m.delete(k),
    clear: () => m.clear(), key: () => null, get length() { return m.size; } } as Storage;
}
const p = (q = '') => new URLSearchParams(q);

describe('WorkSuite Cloud auto sign-in', () => {
  it('tries once per tab, then not again', () => {
    const tab = store(); const dev = store();
    expect(shouldAutoSignIn({ enabled: true, signedIn: false, params: p() }, tab, dev)).toBe(true);
    expect(tab.getItem(TRIED_KEY)).toBe('1');
    expect(shouldAutoSignIn({ enabled: true, signedIn: false, params: p() }, tab, dev)).toBe(false);
  });

  it('is off when not configured, signed in, ?local=1, or a WSC result is showing', () => {
    for (const [o, q] of [[{ enabled: false, signedIn: false }, ''], [{ enabled: true, signedIn: true }, ''],
      [{ enabled: true, signedIn: false }, 'local=1'], [{ enabled: true, signedIn: false }, 'wsc_error=x'],
      [{ enabled: true, signedIn: false }, 'wsc=ok']] as const) {
      expect(shouldAutoSignIn({ ...o, params: p(q) }, store(), store())).toBe(false);
    }
  });

  it('stays off after an explicit sign-out until a manual sign-in', () => {
    const dev = store();
    markSignedOut(dev);
    expect(dev.getItem(SIGNED_OUT_KEY)).toBe('1');
    expect(shouldAutoSignIn({ enabled: true, signedIn: false, params: p() }, store(), dev)).toBe(false);
    clearSignedOut(dev);
    expect(shouldAutoSignIn({ enabled: true, signedIn: false, params: p() }, store(), dev)).toBe(true);
  });

  it('does nothing when storage is unavailable', () => {
    expect(shouldAutoSignIn({ enabled: true, signedIn: false, params: p() }, null, null)).toBe(false);
  });
});

describe('WSC_SSO_POLICY on the sign-in page', () => {
  it('leads with WorkSuite Cloud only under preferred and required, and not with ?local=1', () => {
    expect(leadsWithWsc({ enabled: true, policy: 'oneauth_optional', params: p() })).toBe(false);
    expect(leadsWithWsc({ enabled: true, policy: 'oneauth_preferred', params: p() })).toBe(true);
    expect(leadsWithWsc({ enabled: true, policy: 'oneauth_required', params: p() })).toBe(true);
    expect(leadsWithWsc({ enabled: true, policy: 'oneauth_required', params: p('local=1') })).toBe(false);
    expect(leadsWithWsc({ enabled: false, policy: 'local', params: p() })).toBe(false);
    expect(leadsWithWsc({ enabled: true, params: p() })).toBe(false);
  });
});
