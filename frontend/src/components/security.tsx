import { useState } from 'react';
import { ArrowDownTrayIcon, CheckIcon, ClipboardDocumentIcon } from '@heroicons/react/24/outline';
import { Button, Input } from './ui';

/** Secret + code entry for adding an authenticator app (Login's enforced setup and Account → Security). */
export function TotpEnrol({ secret, uri, code, onCode, error }: { secret: string; uri: string; code: string; onCode: (v: string) => void; error?: string }) {
  const [copied, setCopied] = useState(false);
  const copy = () => navigator.clipboard?.writeText(secret).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); });
  return (
    <div className="space-y-4">
      <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
        <li>Open an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…).</li>
        <li>Add an account by entering this setup key — or, on your phone, <a href={uri} className="font-medium text-brand-600 hover:underline dark:text-blue-400">tap here to open the app</a>.</li>
        <li>Type the 6-digit code it shows.</li>
      </ol>
      <div className="flex items-center gap-2 rounded-md bg-slate-50 px-3 py-2 ring-1 ring-slate-200 ring-inset dark:bg-white/5 dark:ring-white/10">
        <code className="flex-1 font-mono text-sm tracking-wider break-all text-slate-900 dark:text-white">{secret.match(/.{1,4}/g)?.join(' ')}</code>
        <Button size="sm" variant="ghost" icon={copied ? CheckIcon : ClipboardDocumentIcon} onClick={copy}>{copied ? 'Copied' : 'Copy'}</Button>
      </div>
      <Input
        label="6-digit code"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={7}
        required
        autoFocus
        value={code}
        onChange={(e) => onCode(e.target.value.replace(/[^\d ]/g, ''))}
        error={error}
      />
    </div>
  );
}

/** One-time recovery codes, shown once after 2FA is turned on or the codes are regenerated. */
export function RecoveryCodes({ codes }: { codes: string[] }) {
  const [copied, setCopied] = useState(false);
  const text = codes.join('\n');
  const download = () => {
    const href = URL.createObjectURL(new Blob([`WorkSuite AI Studio recovery codes\nEach code works once.\n\n${text}\n`], { type: 'text/plain' }));
    Object.assign(document.createElement('a'), { href, download: 'aistudio-recovery-codes.txt' }).click();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  };
  return (
    <div className="space-y-3">
      <p className="text-sm text-slate-600 dark:text-slate-300">
        If you lose your phone, sign in with one of these codes instead of an authenticator code. Each works once. Store them somewhere safe — they won't be shown again.
      </p>
      <ul className="grid grid-cols-2 gap-2 rounded-md bg-slate-50 p-3 font-mono text-sm text-slate-900 ring-1 ring-slate-200 ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10">
        {codes.map((c) => <li key={c}>{c}</li>)}
      </ul>
      <div className="flex gap-2">
        <Button size="sm" icon={copied ? CheckIcon : ClipboardDocumentIcon} onClick={() => navigator.clipboard?.writeText(text).then(() => setCopied(true))}>{copied ? 'Copied' : 'Copy'}</Button>
        <Button size="sm" icon={ArrowDownTrayIcon} onClick={download}>Download</Button>
      </div>
    </div>
  );
}
