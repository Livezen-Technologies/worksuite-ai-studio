import { describe, expect, it } from 'vitest';
import { validatePasswordChange } from './passwordChange';

const ok = { current: 'old-password-1', next: 'a-much-longer-pass', confirm: 'a-much-longer-pass' };

describe('validatePasswordChange', () => {
  it('accepts a valid change', () => expect(validatePasswordChange(ok, 'me@x.io')).toEqual({}));
  it('requires the current password', () => expect(validatePasswordChange({ ...ok, current: '' }).current_password).toBeTruthy());
  it('enforces 12 characters', () => {
    expect(validatePasswordChange({ ...ok, next: 'short', confirm: 'short' }).new_password).toMatch(/12/);
    expect(validatePasswordChange({ ...ok, next: '123456789012', confirm: '123456789012' })).toEqual({});
  });
  it('rejects the email as password', () => {
    const p = 'Someone@Example.com';
    expect(validatePasswordChange({ ...ok, next: p, confirm: p }, 'someone@example.com').new_password).toMatch(/email/);
  });
  it('rejects reusing the current password', () => {
    expect(validatePasswordChange({ current: ok.next, next: ok.next, confirm: ok.next }).new_password).toBeTruthy();
  });
  it('requires the confirmation to match', () => expect(validatePasswordChange({ ...ok, confirm: 'nope' }).confirm).toBeTruthy());
});
