import { cx } from '../components/ui';

/** WorkSuite AI Studio mark: the WorkSuite blue tile with a spark. */
export function Logo({ className, compact }: { className?: string; compact?: boolean }) {
  return (
    <div className={cx('flex items-center gap-2.5', className)}>
      <span className="flex size-8 items-center justify-center rounded-lg bg-brand-600 text-white shadow-sm">
        <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M12 3v3M12 18v3M3 12h3M18 12h3M6 6l2 2M16 16l2 2M6 18l2-2M16 8l2-2" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </span>
      {!compact && (
        <span className="leading-tight">
          <span className="block text-sm font-semibold text-slate-900 dark:text-white">WorkSuite</span>
          <span className="block text-xs text-slate-500 dark:text-slate-400">AI Studio</span>
        </span>
      )}
    </div>
  );
}
