import type { ComponentType, ReactNode, SVGProps } from 'react';
import { ExclamationTriangleIcon, InboxIcon } from '@heroicons/react/24/outline';
import { cx } from './cx';

export function Spinner({ className }: { className?: string }) {
  return (
    <svg className={cx('animate-spin', className ?? 'size-5')} viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity=".25" strokeWidth="3" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cx('animate-pulse rounded-md bg-slate-200/80 dark:bg-white/10', className ?? 'h-4 w-full')} />;
}

/** Placeholder rows shaped like a table while it loads. */
export function SkeletonRows({ rows = 6, cols = 4 }: { rows?: number; cols?: number }) {
  return (
    <div className="divide-y divide-slate-200 dark:divide-white/10" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, r) => (
        <div key={r} className="flex gap-6 px-4 py-4 sm:px-6">
          {Array.from({ length: cols }, (_, c) => (
            <Skeleton key={c} className={cx('h-4', c === 0 ? 'w-1/3' : 'w-1/6')} />
          ))}
        </div>
      ))}
    </div>
  );
}

export function EmptyState({
  icon: Icon = InboxIcon,
  title,
  description,
  action,
  className,
}: {
  icon?: ComponentType<SVGProps<SVGSVGElement>>;
  title: string;
  description?: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cx('px-6 py-14 text-center', className)}>
      <Icon aria-hidden className="mx-auto size-10 text-slate-400 dark:text-slate-500" />
      <h3 className="mt-3 text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
      {description && <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{description}</p>}
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry, className }: { error: unknown; onRetry?: () => void; className?: string }) {
  const message = error instanceof Error ? error.message : 'Something went wrong.';
  return (
    <div role="alert" className={cx('px-6 py-12 text-center', className)}>
      <ExclamationTriangleIcon aria-hidden className="mx-auto size-10 text-red-500" />
      <h3 className="mt-3 text-sm font-semibold text-slate-900 dark:text-white">Couldn't load this</h3>
      <p className="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">{message}</p>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-4 text-sm font-semibold text-brand-600 hover:text-brand-500 dark:text-blue-400"
        >
          Try again
        </button>
      )}
    </div>
  );
}

type Tone = 'info' | 'success' | 'warning' | 'danger';
const alertTone: Record<Tone, string> = {
  info: 'bg-blue-50 text-blue-800 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-400/20',
  success: 'bg-green-50 text-green-800 ring-green-600/20 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-400/20',
  warning: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
  danger: 'bg-red-50 text-red-800 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20',
};

export function Alert({ tone = 'info', title, children, className }: { tone?: Tone; title?: string; children?: ReactNode; className?: string }) {
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={cx('rounded-md p-3 text-sm ring-1 ring-inset', alertTone[tone], className)}>
      {title && <p className="font-semibold">{title}</p>}
      {children && <div className={cx(title && 'mt-1')}>{children}</div>}
    </div>
  );
}
