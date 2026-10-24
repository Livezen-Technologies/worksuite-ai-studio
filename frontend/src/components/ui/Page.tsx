import type { ReactNode } from 'react';
import { MagnifyingGlassIcon } from '@heroicons/react/20/solid';
import { cx } from './cx';

/** Page heading with actions (Tailwind Plus "page heading with actions"); breadcrumbs come from the shell. */
export function PageHeader({ title, description, actions, meta }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; meta?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <h1 className="text-2xl/8 font-semibold tracking-tight text-slate-900 sm:truncate dark:text-white">{title}</h1>
        {description && <p className="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{description}</p>}
        {meta && <div className="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-slate-500 dark:text-slate-400">{meta}</div>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** Search box + filters + right-side controls above a table. */
export function Toolbar({ children, right, className }: { children?: ReactNode; right?: ReactNode; className?: string }) {
  return (
    <div className={cx('flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-white/10', className)}>
      <div className="flex flex-1 flex-wrap items-center gap-2">{children}</div>
      {right && <div className="flex items-center gap-2">{right}</div>}
    </div>
  );
}

export function SearchInput({ value, onChange, placeholder = 'Search…', className }: { value: string; onChange: (v: string) => void; placeholder?: string; className?: string }) {
  return (
    <div className={cx('relative w-full sm:max-w-xs', className)}>
      <MagnifyingGlassIcon aria-hidden className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
      <input
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="block w-full rounded-md bg-white py-1.5 pr-3 pl-8 text-sm text-slate-900 outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10"
      />
    </div>
  );
}

export interface TabItem { key: string; label: ReactNode; count?: number }

/** Underline tabs on desktop, a select on phones (Tailwind Plus "tabs with underline"). */
export function Tabs({ tabs, value, onChange, className }: { tabs: TabItem[]; value: string; onChange: (k: string) => void; className?: string }) {
  return (
    <div className={className}>
      <div className="sm:hidden">
        <select
          aria-label="Section"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="block w-full rounded-md bg-white py-2 pr-8 pl-3 text-sm text-slate-900 outline-1 -outline-offset-1 outline-slate-300 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:*:bg-slate-800"
        >
          {tabs.map((t) => (
            <option key={t.key} value={t.key}>{typeof t.label === 'string' ? t.label : t.key}</option>
          ))}
        </select>
      </div>
      <div className="hidden border-b border-slate-200 sm:block dark:border-white/10">
        <nav aria-label="Tabs" className="-mb-px flex gap-x-6 overflow-x-auto">
          {tabs.map((t) => {
            const on = t.key === value;
            return (
              <button
                key={t.key}
                type="button"
                onClick={() => onChange(t.key)}
                aria-current={on ? 'page' : undefined}
                className={cx(
                  'flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap transition-colors',
                  on
                    ? 'border-brand-600 text-brand-600 dark:border-blue-400 dark:text-blue-400'
                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-white/20 dark:hover:text-white',
                )}
              >
                {t.label}
                {t.count !== undefined && (
                  <span className={cx('rounded-full px-2 py-0.5 text-xs font-medium tabular-nums', on ? 'bg-brand-50 text-brand-600 dark:bg-blue-500/15 dark:text-blue-300' : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300')}>
                    {t.count}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>
    </div>
  );
}

/** Segmented control for small filters (status, range). */
export function Segmented<T extends string>({ options, value, onChange, label }: { options: { value: T; label: string }[]; value: T; onChange: (v: T) => void; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-md bg-slate-100 p-0.5 dark:bg-white/5">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={o.value === value}
          onClick={() => onChange(o.value)}
          className={cx(
            'rounded px-2.5 py-1 text-xs font-medium transition-colors',
            o.value === value ? 'bg-white text-slate-900 shadow-xs dark:bg-white/15 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

export function CodeBlock({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <pre className={cx('overflow-x-auto rounded-md bg-slate-900 p-3 font-mono text-xs/5 text-slate-100 ring-1 ring-slate-900/10 dark:bg-black/40 dark:ring-white/10', className)}>
      {children}
    </pre>
  );
}
