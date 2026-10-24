import type { ReactNode } from 'react';
import { cx } from './cx';

export type BadgeTone = 'gray' | 'blue' | 'green' | 'amber' | 'red' | 'purple' | 'indigo';
const tones: Record<BadgeTone, string> = {
  gray: 'bg-slate-50 text-slate-600 ring-slate-500/10 dark:bg-slate-400/10 dark:text-slate-400 dark:ring-slate-400/20',
  blue: 'bg-blue-50 text-blue-700 ring-blue-700/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30',
  green: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-500 dark:ring-amber-400/20',
  red: 'bg-red-50 text-red-700 ring-red-600/10 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/20',
  purple: 'bg-purple-50 text-purple-700 ring-purple-700/10 dark:bg-purple-400/10 dark:text-purple-400 dark:ring-purple-400/30',
  indigo: 'bg-indigo-50 text-indigo-700 ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30',
};

export function Badge({ tone = 'gray', dot, children, className }: { tone?: BadgeTone; dot?: boolean; children: ReactNode; className?: string }) {
  return (
    <span className={cx('inline-flex items-center gap-x-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', tones[tone], className)}>
      {dot && <svg viewBox="0 0 6 6" aria-hidden className="size-1.5 fill-current"><circle r={3} cx={3} cy={3} /></svg>}
      {children}
    </span>
  );
}

/** One mapping from the API's status words to a tone, so every module colours them alike. */
const statusTone: Record<string, BadgeTone> = {
  active: 'green', online: 'green', healthy: 'green', completed: 'green', success: 'green', ok: 'green', ready: 'green', loaded: 'green', enabled: 'green', up: 'green',
  pending: 'amber', queued: 'amber', running: 'blue', processing: 'blue', training: 'blue', indexing: 'blue', degraded: 'amber', warning: 'amber', draft: 'gray',
  failed: 'red', error: 'red', offline: 'red', down: 'red', unhealthy: 'red', revoked: 'red', critical: 'red',
  inactive: 'gray', disabled: 'gray', cancelled: 'gray', suspended: 'gray', paused: 'gray', unloaded: 'gray', unknown: 'gray',
};

export function StatusBadge({ status, label }: { status?: string | null; label?: string }) {
  const s = String(status ?? 'unknown').toLowerCase();
  const text = label ?? s.replace(/_/g, ' ');
  return <Badge tone={statusTone[s] ?? 'gray'} dot>{text.charAt(0).toUpperCase() + text.slice(1)}</Badge>;
}
