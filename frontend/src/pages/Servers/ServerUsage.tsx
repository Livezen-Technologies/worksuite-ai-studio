import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartBarIcon } from '@heroicons/react/24/outline';
import { Badge, Card, CardHeader, EmptyState, ErrorState, Segmented, SkeletonRows, StatusBadge } from '../../components/ui';
import { get } from '../../lib/api';
import { compact, dateTime, ms, num } from '../../lib/format';

interface Usage {
  days: number;
  totals: { requests: number; errors: number; prompt_tokens: number; completion_tokens: number; tokens: number; avg_ms: number; uptime_pct: number | null };
  daily: { day: string; requests: number; errors: number; tokens: number }[];
  by_model: { model: string; requests: string; tokens: string; avg_ms: string | null; errors: string }[];
  recent: { created_at: string; endpoint: string; model: string | null; status: string; total_tokens: string | null; processing_ms: string | null; error: string | null }[];
  checks: { checked_at: string; status: string; health_score: string; latency_ms: string | null; models_total: string | null; models_running: string | null; error: string | null }[];
}

const RANGES = [{ value: '1', label: '24 h' }, { value: '7', label: '7 days' }, { value: '30', label: '30 days' }];

function useUsage(id: number, days: string) {
  return useQuery({ queryKey: ['server-usage', id, days], queryFn: () => get<Usage>(`/servers/${id}/usage?days=${days}`), refetchInterval: 60_000 });
}

/** Usage tab: what this server did for AI Studio (gateway calls routed to it) and how available it was. */
export function ServerUsage({ id }: { id: number }) {
  const [days, setDays] = useState('7');
  const q = useUsage(id, days);
  const u = q.data;

  return (
    <div className="space-y-4">
      <Segmented label="Range" value={days} onChange={setDays} options={RANGES} />
      {q.isLoading ? <SkeletonRows rows={5} cols={4} /> : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : u && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Figure label="Requests" value={num(u.totals.requests)} />
            <Figure label="Errors" value={num(u.totals.errors)} hint={u.totals.requests ? `${((u.totals.errors / u.totals.requests) * 100).toFixed(1)}%` : undefined} />
            <Figure label="Tokens" value={compact(u.totals.tokens)} hint={`${compact(u.totals.prompt_tokens)} in · ${compact(u.totals.completion_tokens)} out`} />
            <Figure label="Avg response" value={u.totals.requests ? ms(u.totals.avg_ms) : '—'} />
            <Figure label="Uptime" value={u.totals.uptime_pct != null ? `${u.totals.uptime_pct}%` : '—'} hint="share of health checks answered" />
          </div>
          <Card>
            <CardHeader title="Requests per day" />
            {u.totals.requests ? (
              <div className="h-52 p-4 text-slate-400 sm:px-6 dark:text-slate-500">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={u.daily} margin={{ top: 5, right: 5, left: -20, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.25} vertical={false} />
                    <XAxis dataKey="day" tickFormatter={(d: string) => d.slice(5)} tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                    <YAxis allowDecimals={false} tick={{ fontSize: 10, fill: 'currentColor' }} stroke="currentColor" />
                    <Tooltip contentStyle={{ fontSize: 12, borderRadius: 6 }} />
                    <Bar dataKey="requests" name="Requests" stackId="a" fill="#4f46e5" isAnimationActive={false} />
                    <Bar dataKey="errors" name="Errors" stackId="a" fill="#dc2626" isAnimationActive={false} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            ) : <EmptyState icon={ChartBarIcon} title="No requests in this range" description="Gateway and playground calls routed to this server appear here." />}
          </Card>
          {u.by_model.length > 0 && (
            <Card>
              <CardHeader title="By model" />
              <table className="w-full text-sm">
                <thead className="text-left text-xs text-slate-500 dark:text-slate-400">
                  <tr><th className="px-4 py-2 font-medium sm:px-6">Model</th><th className="px-4 py-2 text-right font-medium">Requests</th><th className="px-4 py-2 text-right font-medium">Tokens</th><th className="hidden px-4 py-2 text-right font-medium sm:table-cell">Avg</th><th className="px-4 py-2 text-right font-medium sm:px-6">Errors</th></tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-white/5">
                  {u.by_model.map((m) => (
                    <tr key={m.model}>
                      <td className="max-w-0 truncate px-4 py-2 font-mono sm:px-6">{m.model}</td>
                      <td className="px-4 py-2 text-right tabular-nums">{num(m.requests)}</td>
                      <td className="px-4 py-2 text-right tabular-nums">{compact(m.tokens)}</td>
                      <td className="hidden px-4 py-2 text-right tabular-nums sm:table-cell">{ms(m.avg_ms)}</td>
                      <td className="px-4 py-2 text-right tabular-nums sm:px-6">{num(m.errors)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}
        </>
      )}
    </div>
  );
}

/** Logs tab: the latest requests this server handled and its health-check history. */
export function ServerLogs({ id }: { id: number }) {
  const [view, setView] = useState<'requests' | 'checks'>('requests');
  const q = useUsage(id, '1');
  const u = q.data;

  return (
    <div className="space-y-4">
      <Segmented label="Log" value={view} onChange={setView} options={[{ value: 'requests', label: 'Requests' }, { value: 'checks', label: 'Health checks' }]} />
      {q.isLoading ? <SkeletonRows rows={6} cols={4} /> : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : u && (
        <Card>
          {view === 'requests' ? (
            u.recent.length ? (
              <ul className="divide-y divide-slate-100 font-mono text-xs dark:divide-white/5">
                {u.recent.map((r, i) => (
                  <li key={i} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 sm:px-6">
                    <span className="text-slate-500 dark:text-slate-400">{dateTime(r.created_at)}</span>
                    <Badge tone={Number(r.status) >= 400 ? 'red' : 'green'}>{r.status}</Badge>
                    <span className="text-slate-800 dark:text-slate-100">{r.endpoint}</span>
                    {r.model && <span className="text-slate-500 dark:text-slate-400">{r.model}</span>}
                    <span className="ml-auto text-slate-500 dark:text-slate-400">{r.total_tokens ? `${num(r.total_tokens)} tok · ` : ''}{ms(r.processing_ms)}</span>
                    {r.error && <span className="w-full text-red-600 dark:text-red-400">{r.error}</span>}
                  </li>
                ))}
              </ul>
            ) : <EmptyState icon={ChartBarIcon} title="No requests yet" description="Calls routed to this server will be listed here." />
          ) : u.checks.length ? (
            <ul className="divide-y divide-slate-100 font-mono text-xs dark:divide-white/5">
              {u.checks.map((c, i) => (
                <li key={i} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 sm:px-6">
                  <span className="text-slate-500 dark:text-slate-400">{dateTime(c.checked_at)}</span>
                  <StatusBadge status={c.status} />
                  <span className="text-slate-800 dark:text-slate-100">score {c.health_score}</span>
                  <span className="ml-auto text-slate-500 dark:text-slate-400">{c.latency_ms != null ? ms(c.latency_ms) : '—'}{c.models_total != null ? ` · ${c.models_total} models` : ''}{Number(c.models_running) ? ` · ${c.models_running} loaded` : ''}</span>
                  {c.error && <span className="w-full text-red-600 dark:text-red-400">{c.error}</span>}
                </li>
              ))}
            </ul>
          ) : <EmptyState icon={ChartBarIcon} title="No health checks yet" description="Checks run every few minutes." />}
        </Card>
      )}
    </div>
  );
}

function Figure({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
      <div className="text-xs text-slate-500 dark:text-slate-400">{label}</div>
      <div className="mt-1 text-lg font-semibold text-slate-900 tabular-nums dark:text-white">{value}</div>
      {hint && <div className="truncate text-xs text-slate-500 dark:text-slate-400">{hint}</div>}
    </div>
  );
}
