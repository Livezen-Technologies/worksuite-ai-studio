import type { UseQueryResult } from '@tanstack/react-query';
import { SparklesIcon } from '@heroicons/react/24/outline';
import { Card, CardHeader, ErrorState, Skeleton } from '../../components/ui';
import type { Preset } from './shared';

export default function PresetList({ presets, building, applyPreset }: {
  presets: UseQueryResult<Preset[]>; building: boolean; applyPreset: (p: Preset) => void;
}) {
  return (
  <Card>
    <CardHeader title="SinLama presets" description="Sinhala personas with tuned parameters. Applying one fills the form." />
    {presets.isError ? (
      <ErrorState error={presets.error} onRetry={() => presets.refetch()} />
    ) : presets.isLoading ? (
      <div className="space-y-3 p-4"><Skeleton className="h-14" /><Skeleton className="h-14" /><Skeleton className="h-14" /></div>
    ) : (
      <ul className="divide-y divide-slate-100 dark:divide-white/5">
        {(presets.data ?? []).map((p) => (
          <li key={p.key}>
            <button
              type="button"
              onClick={() => applyPreset(p)}
              disabled={building}
              className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-slate-50 disabled:opacity-50 sm:px-6 dark:hover:bg-white/5"
            >
              <SparklesIcon aria-hidden className="mt-0.5 size-5 shrink-0 text-brand-600 dark:text-blue-400" />
              <span className="min-w-0">
                <span className="block text-sm font-medium text-slate-900 dark:text-white">{p.label}</span>
                <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                  {p.name} · temp {p.parameters.temperature ?? '—'} · ctx {p.parameters.num_ctx ?? '—'}
                </span>
              </span>
            </button>
          </li>
        ))}
      </ul>
    )}
  </Card>
  );
}
