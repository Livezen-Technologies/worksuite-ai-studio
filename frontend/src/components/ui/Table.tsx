import type { ReactNode } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/20/solid';
import { cx } from './cx';
import { EmptyState, ErrorState, SkeletonRows } from './Feedback';

export interface Column<T> {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  className?: string;
  /** Hidden below this breakpoint so tables stay readable on phones. */
  hideBelow?: 'sm' | 'md' | 'lg';
  align?: 'left' | 'right';
}

const hide = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' };

/**
 * The one table every list uses (Tailwind Plus "simple in card"): loading skeleton, error with
 * retry, and an empty state are part of the component so no page forgets them.
 */
export function DataTable<T>({ columns, rows, rowKey, loading, error, onRetry, empty, onRowClick, footer, dense }: {
  columns: Column<T>[];
  rows: T[] | undefined;
  rowKey: (row: T) => string | number;
  loading?: boolean;
  error?: unknown;
  onRetry?: () => void;
  empty?: ReactNode;
  onRowClick?: (row: T) => void;
  footer?: ReactNode;
  dense?: boolean;
}) {
  if (error) return <ErrorState error={error} onRetry={onRetry} />;
  if (loading && !rows) return <SkeletonRows cols={Math.min(columns.length, 5)} />;
  if (!rows?.length) return <>{empty ?? <EmptyState title="Nothing here yet" />}</>;
  return (
    <>
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 dark:divide-white/10">
          <thead className="bg-slate-50/80 dark:bg-white/[0.02]">
            <tr>
              {columns.map((c, i) => (
                <th
                  key={c.key}
                  scope="col"
                  className={cx(
                    'py-3 text-xs font-semibold tracking-wide whitespace-nowrap text-slate-600 uppercase dark:text-slate-400',
                    i === 0 ? 'pr-3 pl-4 sm:pl-6' : 'px-3',
                    i === columns.length - 1 && 'pr-4 sm:pr-6',
                    c.align === 'right' ? 'text-right' : 'text-left',
                    c.hideBelow && hide[c.hideBelow],
                    c.className,
                  )}
                >
                  {c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className={cx('divide-y divide-slate-100 bg-white dark:divide-white/5 dark:bg-transparent', loading && 'opacity-60 transition-opacity')}>
            {rows.map((r) => (
              <tr
                key={rowKey(r)}
                onClick={onRowClick ? () => onRowClick(r) : undefined}
                className={cx('transition-colors', onRowClick && 'cursor-pointer hover:bg-slate-50 dark:hover:bg-white/[0.03]')}
              >
                {columns.map((c, i) => (
                  <td
                    key={c.key}
                    className={cx(
                      'text-sm text-slate-700 dark:text-slate-300',
                      dense ? 'py-2' : 'py-3.5',
                      i === 0 ? 'pr-3 pl-4 font-medium text-slate-900 sm:pl-6 dark:text-white' : 'px-3',
                      i === columns.length - 1 && 'pr-4 sm:pr-6',
                      c.align === 'right' && 'text-right',
                      c.hideBelow && hide[c.hideBelow],
                      c.className,
                    )}
                  >
                    {c.cell(r)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {footer}
    </>
  );
}

export function Pagination({ page, perPage, total, onPage }: { page: number; perPage: number; total: number; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / perPage));
  if (total <= perPage) return null;
  const from = (page - 1) * perPage + 1;
  const to = Math.min(total, page * perPage);
  return (
    <nav aria-label="Pagination" className="flex items-center justify-between border-t border-slate-200 px-4 py-3 sm:px-6 dark:border-white/10">
      <p className="text-sm text-slate-600 dark:text-slate-400">
        <span className="font-medium tabular-nums">{from}</span>–<span className="font-medium tabular-nums">{to}</span> of{' '}
        <span className="font-medium tabular-nums">{total.toLocaleString()}</span>
      </p>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
          className="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-slate-900 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 disabled:opacity-40 dark:bg-white/10 dark:text-white dark:ring-white/10"
        >
          <ChevronLeftIcon aria-hidden className="size-4" /> Previous
        </button>
        <button
          type="button"
          disabled={page >= pages}
          onClick={() => onPage(page + 1)}
          className="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-slate-900 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 disabled:opacity-40 dark:bg-white/10 dark:text-white dark:ring-white/10"
        >
          Next <ChevronRightIcon aria-hidden className="size-4" />
        </button>
      </div>
    </nav>
  );
}
