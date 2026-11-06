/** Client-side checks for Account → Change password; the server re-checks (and rejects common passwords). */
export const MIN_PASSWORD_LENGTH = 12;

export interface PasswordChangeForm { current: string; next: string; confirm: string }
export type PasswordChangeErrors = Partial<Record<'current_password' | 'new_password' | 'confirm', string>>;

export function validatePasswordChange(f: PasswordChangeForm, email?: string | null): PasswordChangeErrors {
  const e: PasswordChangeErrors = {};
  if (!f.current) e.current_password = 'Enter your current password.';
  if (f.next.length < MIN_PASSWORD_LENGTH) e.new_password = `Use at least ${MIN_PASSWORD_LENGTH} characters.`;
  else if (email && f.next.trim().toLowerCase() === email.trim().toLowerCase()) e.new_password = "Don't use your email address as your password.";
  else if (f.current && f.next === f.current) e.new_password = 'Choose a password different from your current one.';
  if (f.confirm !== f.next) e.confirm = "The passwords don't match.";
  return e;
}
