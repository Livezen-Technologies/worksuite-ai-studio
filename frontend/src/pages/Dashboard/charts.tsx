/**
 * Shared chart pieces for Dashboard, Monitoring and Analytics.
 * Theme-aware: axes and grid draw in currentColor inside a slate text wrapper,
 * the tooltip is a Tailwind card with dark: counterparts.
 */
import type { ReactNode } from 'react';
import { ResponsiveContainer } from 'recharts';
import { ChartBarIcon } from '@heroicons/react/24/outline';
import { Card, CardHeader, Skeleton, cx } from '../../components/ui';
import { num } from '../../lib/format';

/** Series colours readable on white and on slate-900. */
export const SERIES = ['#6366f1', '#38bdf8', '#f59e0b', '#10b981', '#f43f5e', '#a855f7', '#14b8a6', '#eab308', '#64748b', '#ec4899'];
export const PROMPT_COLOR = '#a5b4fc';
export const COMPLETION_COLOR = '#6366f1';

export const axisProps = {
  tick: { fill: 'currentColor', fontSize: 11 },
  tickLine: false,
  axisLine: false,
  stroke: 'currentColor',
} as const;
export const gridProps = { stroke: 'currentColor', strokeOpacity: 0.12, strokeDasharray: '3 3', vertical: false } as const;
export const cursorFill = { fill: 'currentColor', fillOpacity: 0.06 };

interface TipItem { name?: string | number; value?: unknown; color?: string; dataKey?: unknown; payload?: Record<string, unknown> }

/** Tooltip body; pass as `content={<ChartTooltip ... />}`. Recharts injects active/payload/label. */
export function ChartTooltip({ active, payload, label, labelFormat, valueFormat = num }: {
  active?: boolean; payload?: readonly TipItem[]; label?: unknown;
  labelFormat?: (l: unknown) => ReactNode; valueFormat?: (v: unknown) => ReactNode;
}) {
  if (!active || !payload?.length) return null;
  const head = labelFormat ? labelFormat(label) : label != null && label !== '' ? String(label) : payload[0]?.name;
  return (
    <div className="rounded-md bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-900/10 dark:bg-slate-800 dark:ring-white/10">
      {head != null && head !== '' && <div className="mb-1 font-medium text-slate-900 dark:text-white">{head as ReactNode}</div>}
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
          <span className="size-2 rounded-full" style={{ background: p.color ?? (p.payload?.fill as string | undefined) }} />
          <span className="capitalize">{String(p.name ?? '')}</span>
          <span className="ml-auto pl-3 font-semibold text-slate-900 tabular-nums dark:text-white">{valueFormat(p.value)}</span>
        </div>
      ))}
    </div>
  );
}

/** Card holding one chart, with loading/empty states and a fixed height. */
export function ChartCard({ title, description, actions, loading, empty, emptyText = 'No data yet.', height = 240, className, legend, children }: {
  title: ReactNode; description?: ReactNode; actions?: ReactNode; loading?: boolean; empty?: boolean; emptyText?: string;
  height?: number; className?: string; legend?: ReactNode; children: ReactNode;
}) {
  return (
    <Card className={cx('flex flex-col', className)}>
      <CardHeader title={title} description={description} actions={actions} />
      <div className="flex-1 px-4 pt-2 pb-4 text-slate-500 sm:px-6 dark:text-slate-400">
        {legend && <div className="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">{legend}</div>}
        {loading ? (
          <div style={{ height }}><Skeleton className="h-full w-full" /></div>
        ) : empty ? (
          <div className="flex flex-col items-center justify-center gap-2 text-sm text-slate-400 dark:text-slate-500" style={{ height }}>
            <ChartBarIcon aria-hidden className="size-8" />
            {emptyText}
          </div>
        ) : (
          <div style={{ height }}>
            <ResponsiveContainer width="100%" height="100%">{children as React.ReactElement}</ResponsiveContainer>
          </div>
        )}
      </div>
    </Card>
  );
}

export function LegendDot({ color, label }: { color: string; label: ReactNode }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
      <span className="size-2.5 rounded-sm" style={{ background: color }} />
      {label}
    </span>
  );
}

/** "2026-10-24" → "Oct 24" (date-only, no zone shift). */
export function shortDay(v: unknown): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v ?? ''));
  if (!m) return String(v ?? '');
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/** "2026-10-24 13:00" (server UTC hour bucket) → local "1 PM". */
export function hourLabel(v: unknown, long = false): string {
  const s = String(v ?? '');
  const d = new Date(s.replace(' ', 'T') + ':00Z');
  if (Number.isNaN(d.getTime())) return s.slice(11, 16);
  return long ? d.toLocaleString(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' }) : d.toLocaleTimeString(undefined, { hour: 'numeric' });
}

/** Fill missing UTC hour buckets ("Y-m-d H:00") so the 24h line has no gaps. */
export function fillHours(rows: { t: string; requests: number }[], hours = 24) {
  const byKey = new Map(rows.map((r) => [r.t, r.requests]));
  const out: { t: string; requests: number }[] = [];
  const now = new Date();
  now.setUTCMinutes(0, 0, 0);
  for (let i = hours - 1; i >= 0; i--) {
    const d = new Date(now.getTime() - i * 3600_000);
    const key = d.toISOString().slice(0, 13).replace('T', ' ') + ':00';
    out.push({ t: key, requests: byKey.get(key) ?? 0 });
  }
  return out;
}

/** Fill missing days ("Y-m-d") between the first requested day and today. */
export function fillDays<T extends { t: string }>(rows: T[], days: number, blank: Omit<T, 't'>): T[] {
  const byKey = new Map(rows.map((r) => [r.t.slice(0, 10), r]));
  const out: T[] = [];
  const today = new Date();
  for (let i = days - 1; i >= 0; i--) {
    const d = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate() - i));
    const key = d.toISOString().slice(0, 10);
    out.push(byKey.get(key) ?? ({ ...blank, t: key } as T));
  }
  return out;
}

/** Health score bar colour: ≥70 healthy, >0 degraded, 0 down. */
export function scoreTone(score: number) {
  return score >= 70 ? 'bg-emerald-500' : score > 0 ? 'bg-amber-500' : 'bg-red-500';
}
export function scoreText(score: number) {
  return score >= 70 ? 'text-emerald-600 dark:text-emerald-400' : score > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400';
}
