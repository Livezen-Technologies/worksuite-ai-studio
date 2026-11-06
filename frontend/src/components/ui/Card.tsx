import type { ComponentType, ReactNode, SVGProps } from 'react';
import { ArrowDownRightIcon, ArrowUpRightIcon } from '@heroicons/react/20/solid';
import { cx } from './cx';
import { Skeleton } from './Feedback';

export function Card({ children, className, padded = false }: { children: ReactNode; className?: string; padded?: boolean }) {
  return (
    <div className={cx('overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-white/10 dark:bg-slate-900 dark:shadow-none', padded && 'p-5', className)}>
      {children}
    </div>
  );
}

export function CardHeader({ title, description, actions, className }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <div className={cx('flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-white/10', className)}>
      <div className="min-w-0">
        <h3 className="text-[17px]/6 font-semibold tracking-tight text-slate-900 dark:text-white">{title}</h3>
        {description && <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </div>
  );
}

export function StatCard({ name, value, icon: Icon, change, changeTone, hint, loading }: {
  name: string; value: ReactNode; icon?: ComponentType<SVGProps<SVGSVGElement>>; change?: string; changeTone?: 'up' | 'down' | 'flat'; hint?: ReactNode; loading?: boolean;
}) {
  return (
    <Card padded>
      <div className="min-w-0">
        <div className="flex items-center gap-2.5">
          {Icon && (
            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 ring-1 ring-brand-100 ring-inset dark:bg-blue-500/10 dark:ring-blue-400/20">
              <Icon aria-hidden className="size-[18px] text-brand-600 dark:text-blue-400" />
            </div>
          )}
          <p className="truncate text-sm font-medium text-slate-700 dark:text-slate-300">{name}</p>
        </div>
        <div className="min-w-0">
          {loading ? (
            <Skeleton className="mt-4 h-8 w-24" />
          ) : (
            <p className="mt-3 flex items-baseline gap-2">
              <span className="text-3xl font-bold tracking-tight text-slate-900 tabular-nums dark:text-white">{value}</span>
              {change && (
                <span className={cx('inline-flex items-center text-xs font-semibold', changeTone === 'down' ? 'text-red-600 dark:text-red-400' : changeTone === 'up' ? 'text-green-600 dark:text-green-400' : 'text-slate-500')}>
                  {changeTone === 'up' && <ArrowUpRightIcon aria-hidden className="size-4" />}
                  {changeTone === 'down' && <ArrowDownRightIcon aria-hidden className="size-4" />}
                  {change}
                </span>
              )}
            </p>
          )}
          {hint && <p className="mt-1.5 truncate text-sm text-slate-500 dark:text-slate-400">{hint}</p>}
        </div>
      </div>
    </Card>
  );
}

/** Label/value rows (Tailwind Plus description list). */
export function DescriptionList({ items, className }: { items: { label: ReactNode; value: ReactNode }[]; className?: string }) {
  return (
    <dl className={cx('divide-y divide-slate-100 dark:divide-white/5', className)}>
      {items.map((it, i) => (
        <div key={i} className="px-5 py-3 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt className="text-sm font-medium text-slate-500 dark:text-slate-400">{it.label}</dt>
          <dd className="mt-1 text-sm break-words text-slate-900 sm:col-span-2 sm:mt-0 dark:text-slate-100">{it.value}</dd>
        </div>
      ))}
    </dl>
  );
}

/** A small horizontal bar for percentages (CPU, memory, disk, quotas). */
export function Meter({ value, max = 100, label, className }: { value: number; max?: number; label?: string; className?: string }) {
  const pct = Math.max(0, Math.min(100, (value / (max || 1)) * 100));
  const tone = pct >= 90 ? 'bg-red-500' : pct >= 75 ? 'bg-amber-500' : 'bg-brand-600 dark:bg-blue-500';
  return (
    <div className={className}>
      {label && (
        <div className="mb-1 flex justify-between text-xs text-slate-500 dark:text-slate-400">
          <span>{label}</span>
          <span className="tabular-nums">{pct.toFixed(0)}%</span>
        </div>
      )}
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10" role="meter" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
        <div className={cx('h-full rounded-full transition-[width] duration-500', tone)} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}
