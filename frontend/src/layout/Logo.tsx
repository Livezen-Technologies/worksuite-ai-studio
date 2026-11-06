import { cx } from '../components/ui';

/**
 * WorkSuite AI Studio: the original WorkSuite logo (public/brand) with the product name. The wordmark swaps to its
 * light-text version in dark mode; `compact` (a collapsed sidebar) shows the four-square mark alone.
 */
export function Logo({ className, compact }: { className?: string; compact?: boolean }) {
  if (compact) {
    return <img src="/brand/worksuite-mark.svg" alt="WorkSuite AI Studio" className={cx('size-8', className)} />;
  }
  return (
    <div className={cx('flex min-w-0 items-center gap-2', className)}>
      <img src="/brand/worksuite-wordmark.png" alt="WorkSuite" className="h-6 w-auto shrink-0 dark:hidden" />
      <img src="/brand/worksuite-wordmark-dark.png" alt="WorkSuite" className="hidden h-6 w-auto shrink-0 dark:block" />
      <span className="border-l border-slate-200 pl-2 text-[13px] leading-5 font-semibold tracking-tight whitespace-nowrap text-slate-700 dark:border-white/15 dark:text-slate-200">AI Studio</span>
    </div>
  );
}
