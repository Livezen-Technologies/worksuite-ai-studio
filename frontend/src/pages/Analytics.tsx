import { useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Line, LineChart, Pie, PieChart, Tooltip, XAxis, YAxis } from 'recharts';
import { ArrowPathIcon, BoltIcon, ChartBarIcon, GlobeAltIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Button, Card, ErrorState, PageHeader, Segmented, StatCard, cx } from '../components/ui';
import { get } from '../lib/api';
import { compact, ms, num } from '../lib/format';
import {
  COMPLETION_COLOR, ChartCard, ChartTooltip, LegendDot, PROMPT_COLOR, SERIES, axisProps, cursorFill, fillDays, gridProps, shortDay,
} from './Dashboard/charts';

interface Overview {
  daily_requests: { t: string; requests: number }[];
  token_usage: { t: string; prompt: number; completion: number }[];
  model_popularity: { model: string; requests: number }[];
  response_time: { t: string; ms: number }[];
  gateway_status: { label: string; value: number }[];
  totals: { requests: number; tokens: number; gateway: number; active_users: number };
}

type Range = '7' | '30' | '90' | '365';
const RANGES: { value: Range; label: string }[] = [
  { value: '7', label: '7 days' }, { value: '30', label: '30 days' }, { value: '90', label: '90 days' }, { value: '365', label: '1 year' },
];
const OUTCOME_COLOR: Record<string, string> = { success: '#10b981', 'client error': '#f59e0b', 'server error': '#f43f5e' };

export default function Analytics() {
  const [range, setRange] = useState<Range>('30');
  const days = Number(range);
  const q = useQuery({
    queryKey: ['analytics', days],
    queryFn: () => get<Overview>('/analytics', { days }),
    refetchInterval: 15_000,
    placeholderData: keepPreviousData,
  });
  const d = q.data;
  const loading = q.isLoading;

  const requests = useMemo(() => fillDays(d?.daily_requests ?? [], days + 1, { requests: 0 }), [d, days]);
  const tokens = useMemo(() => fillDays(d?.token_usage ?? [], days + 1, { prompt: 0, completion: 0 }), [d, days]);
  const latency = d?.response_time ?? [];
  const models = d?.model_popularity ?? [];
  const outcomes = d?.gateway_status ?? [];
  const outcomeTotal = outcomes.reduce((a, o) => a + o.value, 0);
  const successRate = outcomeTotal ? ((outcomes.find((o) => o.label === 'success')?.value ?? 0) / outcomeTotal) * 100 : null;
  const avgLatency = latency.length ? latency.reduce((a, r) => a + r.ms, 0) / latency.length : null;
  const promptSum = tokens.reduce((a, r) => a + r.prompt, 0);
  const completionSum = tokens.reduce((a, r) => a + r.completion, 0);
  const rangeLabel = RANGES.find((r) => r.value === range)!.label;
  const tickGap = days > 30 ? 32 : 16;

  return (
    <>
      <PageHeader
        title="Analytics"
        description="Usage trends across the Playground and the API gateway."
        actions={
          <div className="flex flex-wrap items-center gap-3">
            <Segmented label="Time range" options={RANGES} value={range} onChange={setRange} />
            <Button variant="secondary" icon={ArrowPathIcon} loading={q.isFetching && !loading} onClick={() => q.refetch()}>Refresh</Button>
          </div>
        }
      />

      {q.error && !d ? (
        <Card><ErrorState error={q.error} onRetry={() => q.refetch()} /></Card>
      ) : (
        <div className={cx('transition-opacity', q.isPlaceholderData && 'opacity-60')}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard loading={loading} name="Requests" icon={BoltIcon} value={d ? compact(d.totals.requests) : '—'}
              hint={d ? `${num(d.totals.requests)} in the last ${rangeLabel}` : undefined} />
            <StatCard loading={loading} name="Tokens" icon={ChartBarIcon} value={d ? compact(d.totals.tokens) : '—'}
              hint={d ? `${compact(promptSum)} prompt · ${compact(completionSum)} completion` : undefined} />
            <StatCard loading={loading} name="Gateway calls" icon={GlobeAltIcon} value={d ? compact(d.totals.gateway) : '—'}
              hint={successRate != null ? `${successRate.toFixed(1)}% successful, all time` : 'Through API keys'} />
            <StatCard loading={loading} name="Active users" icon={UsersIcon} value={d ? num(d.totals.active_users) : '—'}
              hint="With a Playground session in range" />
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-2">
            <ChartCard title="Daily requests" description={`Last ${rangeLabel}`} loading={loading} empty={!d?.daily_requests.length}>
              <AreaChart data={requests} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                <defs>
                  <linearGradient id="anReq" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES[0]} stopOpacity={0.35} />
                    <stop offset="100%" stopColor={SERIES[0]} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid {...gridProps} />
                <XAxis dataKey="t" {...axisProps} tickFormatter={shortDay} minTickGap={tickGap} />
                <YAxis {...axisProps} allowDecimals={false} width={48} tickFormatter={(v) => compact(v)} />
                <Tooltip cursor={{ stroke: 'currentColor', strokeOpacity: 0.2 }} content={<ChartTooltip labelFormat={shortDay} />} />
                <Area type="monotone" dataKey="requests" name="Requests" stroke={SERIES[0]} strokeWidth={2} fill="url(#anReq)" isAnimationActive={false} />
              </AreaChart>
            </ChartCard>

            <ChartCard title="Token usage" description={`Prompt and completion tokens per day`} loading={loading} empty={!d?.token_usage.length}
              legend={<><LegendDot color={PROMPT_COLOR} label="Prompt" /><LegendDot color={COMPLETION_COLOR} label="Completion" /></>}>
              <BarChart data={tokens} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                <CartesianGrid {...gridProps} />
                <XAxis dataKey="t" {...axisProps} tickFormatter={shortDay} minTickGap={tickGap} />
                <YAxis {...axisProps} width={48} tickFormatter={(v) => compact(v)} />
                <Tooltip cursor={cursorFill} content={<ChartTooltip labelFormat={shortDay} />} />
                <Bar dataKey="prompt" name="Prompt" stackId="t" fill={PROMPT_COLOR} isAnimationActive={false} />
                <Bar dataKey="completion" name="Completion" stackId="t" fill={COMPLETION_COLOR} radius={[2, 2, 0, 0]} isAnimationActive={false} />
              </BarChart>
            </ChartCard>

            <ChartCard title="Average response time" description={avgLatency != null ? `Mean of daily averages: ${ms(avgLatency)}` : 'Per day'}
              loading={loading} empty={!latency.length}>
              <LineChart data={latency} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                <CartesianGrid {...gridProps} />
                <XAxis dataKey="t" {...axisProps} tickFormatter={shortDay} minTickGap={tickGap} />
                <YAxis {...axisProps} width={56} tickFormatter={(v) => ms(v)} />
                <Tooltip cursor={{ stroke: 'currentColor', strokeOpacity: 0.2 }} content={<ChartTooltip labelFormat={shortDay} valueFormat={ms} />} />
                <Line type="monotone" dataKey="ms" name="Avg response" stroke={SERIES[2]} strokeWidth={2} dot={latency.length < 3} isAnimationActive={false} />
              </LineChart>
            </ChartCard>

            <ChartCard title="Gateway outcomes" description={outcomeTotal ? `${num(outcomeTotal)} gateway calls, all time` : 'All time'}
              loading={loading} empty={!outcomeTotal} emptyText="No gateway calls yet."
              legend={outcomes.map((o, i) => (
                <LegendDot key={o.label} color={OUTCOME_COLOR[o.label] ?? SERIES[i % SERIES.length]}
                  label={<span className="capitalize">{o.label} <span className="text-slate-400 tabular-nums dark:text-slate-500">{((o.value / (outcomeTotal || 1)) * 100).toFixed(1)}%</span></span>} />
              ))}>
              <PieChart>
                <Pie data={outcomes} dataKey="value" nameKey="label" cx="50%" cy="50%" innerRadius="55%" outerRadius="85%" paddingAngle={2}
                  stroke="none" isAnimationActive={false}>
                  {outcomes.map((o, i) => <Cell key={o.label} fill={OUTCOME_COLOR[o.label] ?? SERIES[i % SERIES.length]} />)}
                </Pie>
                <Tooltip content={<ChartTooltip />} />
              </PieChart>
            </ChartCard>
          </div>

          <div className="mt-6">
            <ChartCard title="Model popularity" description="Top 10 models by requests, all time" loading={loading} empty={!models.length}
              emptyText="No model usage yet." height={Math.max(220, models.length * 32)}>
              <BarChart data={models} layout="vertical" margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
                <CartesianGrid {...gridProps} vertical horizontal={false} />
                <XAxis type="number" {...axisProps} allowDecimals={false} tickFormatter={(v) => compact(v)} />
                <YAxis type="category" dataKey="model" {...axisProps} width={160} tickFormatter={(v: string) => (v.length > 24 ? `${v.slice(0, 23)}…` : v)} />
                <Tooltip cursor={cursorFill} content={<ChartTooltip />} />
                <Bar dataKey="requests" name="Requests" radius={[0, 4, 4, 0]} barSize={18} isAnimationActive={false}>
                  {models.map((m, i) => <Cell key={m.model} fill={SERIES[i % SERIES.length]} />)}
                </Bar>
              </BarChart>
            </ChartCard>
          </div>
        </div>
      )}
    </>
  );
}
