import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ErrorState, Field, FormModal, Input, Select, SkeletonRows, Textarea, useToast } from '../../components/ui';
import { ApiError, get, put } from '../../lib/api';

interface Config {
  name: string; exists: boolean;
  temperature: number | string; top_p: number | string; top_k: number | string; repeat_penalty: number | string;
  num_ctx: number | string; num_predict: number | string; seed: number | string | null;
  num_batch: number | string | null; num_thread: number | string | null; num_gpu: number | string | null;
  keep_alive: string; system_prompt: string | null; stop: string[] | null;
}

const KEEP_ALIVE = [
  { value: '0', label: 'Unload immediately' }, { value: '5m', label: '5 minutes' }, { value: '15m', label: '15 minutes' },
  { value: '30m', label: '30 minutes' }, { value: '1h', label: '1 hour' }, { value: '-1', label: 'Keep loaded' },
];

const n = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number(v));

export default function ConfigModal({ model, readOnly, onClose }: { model: { id: number; name: string }; readOnly: boolean; onClose: () => void }) {
  const toast = useToast();
  const q = useQuery({ queryKey: ['model-config', model.id], queryFn: () => get<Config>(`/models/${model.id}/config`) });
  const [c, setC] = useState<Config | null>(null);
  const [stop, setStop] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => { if (q.data) { setC(q.data); setStop((q.data.stop ?? []).join(', ')); } }, [q.data]);
  const set = (k: keyof Config) => (e: { target: { value: string } }) => setC((p) => (p ? { ...p, [k]: e.target.value } : p));

  const save = useMutation({
    mutationFn: () => put(`/models/${model.id}/config`, {
      temperature: Number(c!.temperature), top_p: Number(c!.top_p), top_k: Number(c!.top_k), repeat_penalty: Number(c!.repeat_penalty),
      num_ctx: Number(c!.num_ctx), num_predict: Number(c!.num_predict), seed: n(c!.seed), num_batch: n(c!.num_batch),
      num_thread: n(c!.num_thread), num_gpu: n(c!.num_gpu), keep_alive: c!.keep_alive, system_prompt: c!.system_prompt || null,
      stop: stop.split(',').map((s) => s.trim()).filter(Boolean),
    }),
    onSuccess: () => { toast.success('Configuration saved'); q.refetch(); onClose(); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });

  return (
    <FormModal
      open
      onClose={onClose}
      size="lg"
      title={`Configure ${model.name}`}
      description={c && !c.exists ? 'Using the defaults. Saving stores settings for every copy of this model.' : 'Generation settings apply to every copy of this model across nodes.'}
      submitLabel="Save"
      submitting={save.isPending}
      onSubmit={() => (readOnly ? onClose() : save.mutate())}
    >
      {q.isLoading || (!c && !q.error) ? <SkeletonRows rows={6} cols={3} /> : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : c && (
        <fieldset disabled={readOnly} className="space-y-4">
          <Field label={`Temperature: ${Number(c.temperature).toFixed(2)}`} hint="Higher is more creative, lower is more focused." error={errors.temperature}>
            {(id) => (
              <input id={id} type="range" min={0} max={2} step={0.05} value={Number(c.temperature)} onChange={set('temperature')}
                className="w-full accent-brand-600 dark:accent-blue-500" />
            )}
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Input label="Top P" type="number" min={0} max={1} step={0.05} value={String(c.top_p)} onChange={set('top_p')} error={errors.top_p} />
            <Input label="Top K" type="number" min={0} value={String(c.top_k)} onChange={set('top_k')} error={errors.top_k} />
            <Input label="Repeat penalty" type="number" min={0} max={2} step={0.05} value={String(c.repeat_penalty)} onChange={set('repeat_penalty')} />
            <Input label="Context (num_ctx)" type="number" min={512} step={512} value={String(c.num_ctx)} onChange={set('num_ctx')} />
            <Input label="Max tokens" type="number" min={-1} value={String(c.num_predict)} onChange={set('num_predict')} hint="-1 is unlimited." />
            <Select label="Keep alive" value={c.keep_alive} onChange={set('keep_alive')}
              options={KEEP_ALIVE.some((k) => k.value === c.keep_alive) ? KEEP_ALIVE : [...KEEP_ALIVE, { value: c.keep_alive, label: c.keep_alive }]} />
            <Input label="Seed" type="number" value={String(c.seed ?? '')} onChange={set('seed')} hint="Blank is random." />
            <Input label="Threads" type="number" min={1} value={String(c.num_thread ?? '')} onChange={set('num_thread')} hint="Blank is automatic." />
            <Input label="GPU layers" type="number" min={0} value={String(c.num_gpu ?? '')} onChange={set('num_gpu')} hint="Blank is automatic." />
            <Input label="Batch size" type="number" min={1} value={String(c.num_batch ?? '')} onChange={set('num_batch')} hint="Blank is automatic." />
          </div>
          <Input label="Stop sequences" value={stop} onChange={(e) => setStop(e.target.value)} placeholder="</s>, User:" hint="Comma-separated." />
          <Textarea label="System prompt" rows={3} value={c.system_prompt ?? ''} onChange={set('system_prompt')} placeholder="You are a helpful assistant." />
        </fieldset>
      )}
    </FormModal>
  );
}
