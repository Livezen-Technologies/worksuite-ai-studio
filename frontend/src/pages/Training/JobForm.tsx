import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CpuChipIcon } from '@heroicons/react/24/outline';
import { Alert, FormModal, FormSection, Input, Select, Textarea, useToast } from '../../components/ui';
import { ApiError, get, post, put } from '../../lib/api';
import { num } from '../../lib/format';
import { parseHyper, QUANTS, STATUSES, type Dataset, type Hyper, type Job, type Status } from './shared';

export const HYPER_FIELDS: { key: keyof Hyper; label: string; step?: number; min?: number; hint?: string }[] = [
  { key: 'epochs', label: 'Epochs', min: 1, step: 1 },
  { key: 'learning_rate', label: 'Learning rate', min: 0, step: 0.00001, hint: 'e.g. 0.0002' },
  { key: 'lora_rank', label: 'LoRA rank (r)', min: 1, step: 1 },
  { key: 'lora_alpha', label: 'LoRA alpha', min: 1, step: 1 },
  { key: 'lora_dropout', label: 'LoRA dropout', min: 0, step: 0.01 },
  { key: 'batch_size', label: 'Batch size', min: 1, step: 1 },
  { key: 'grad_accum', label: 'Gradient accumulation', min: 1, step: 1 },
  { key: 'max_seq_len', label: 'Max sequence length', min: 128, step: 128 },
  { key: 'warmup_steps', label: 'Warmup steps', min: 0, step: 1 },
  { key: 'seed', label: 'Seed', min: 0, step: 1 },
];

export default function JobForm({ open, onClose, job, baseModels, defaults }: {
  open: boolean; onClose: () => void; job: Job | null; baseModels: string[]; defaults: Hyper;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const datasets = useQuery({ queryKey: ['datasets'], queryFn: () => get<Dataset[]>('/datasets'), enabled: open });
  const init = { ...defaults, ...parseHyper(job?.hyperparams ?? null) };
  const [name, setName] = useState(job?.name ?? '');
  const [datasetId, setDatasetId] = useState(job?.dataset_id ? String(job.dataset_id) : '');
  const [base, setBase] = useState(job?.base_model ?? baseModels[0] ?? '');
  const [method, setMethod] = useState<'lora' | 'qlora'>(job?.method === 'lora' ? 'lora' : 'qlora');
  const [status, setStatus] = useState<Status>(job?.status ?? 'draft');
  const [notes, setNotes] = useState(job?.notes ?? '');
  const [logs, setLogs] = useState(job?.logs ?? '');
  const [hp, setHp] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(init).map(([k, v]) => [k, String(v)])));
  const [errors, setErrors] = useState<Record<string, string>>({});

  const save = useMutation({
    mutationFn: () => {
      const hyperparams: Record<string, string | number> = {};
      for (const f of HYPER_FIELDS) if (hp[f.key] !== '') hyperparams[f.key] = Number(hp[f.key]);
      hyperparams.quant_export = hp.quant_export || defaults.quant_export;
      const body = {
        name: name.trim(), dataset_id: datasetId ? Number(datasetId) : 0, base_model: base.trim(), method, hyperparams, notes: notes.trim() || null,
        ...(job ? { status, logs: logs || null } : {}),
      };
      return job ? put<Job>(`/training/${job.id}`, body) : post<Job>('/training', body);
    },
    onSuccess: () => {
      toast.success(job ? 'Job updated' : 'Training job created');
      qc.invalidateQueries({ queryKey: ['training'] });
      onClose();
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });

  function submit() {
    const e: Record<string, string> = {};
    if (!name.trim()) e.name = 'Name the job.';
    if (!base.trim()) e.base_model = 'Pick a base model.';
    for (const f of HYPER_FIELDS) if (hp[f.key] !== '' && Number.isNaN(Number(hp[f.key]))) e[f.key] = 'Must be a number.';
    setErrors(e);
    if (!Object.keys(e).length) save.mutate();
  }

  const quantOptions = Array.from(new Set([...QUANTS, String(hp.quant_export || defaults.quant_export)])).map((x) => ({ value: x, label: x }));

  return (
    <FormModal
      open={open}
      onClose={onClose}
      size="xl"
      title={job ? `Edit ${job.name}` : 'New training job'}
      description="Hyperparameters go into the generated train.py. Defaults suit a 3B–8B model on a single 24 GB GPU."
      submitLabel={job ? 'Save changes' : 'Create job'}
      submitting={save.isPending}
      onSubmit={submit}
    >
      <div className="space-y-8">
        <FormSection title="Job">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="sinlama-support-qwen3b" error={errors.name} wrapperClassName="sm:col-span-2" />
            <Select
              label="Dataset"
              value={datasetId}
              onChange={(e) => setDatasetId(e.target.value)}
              options={(datasets.data ?? []).map((d) => ({ value: d.id, label: `${d.name} (${num(d.item_count)} rows)` }))}
              placeholder={datasets.isLoading ? 'Loading…' : 'No dataset yet'}
              hint="Needed before a kit can be generated."
              error={errors.dataset_id}
            />
            <Select label="Method" value={method} onChange={(e) => setMethod(e.target.value as 'lora' | 'qlora')} options={[{ value: 'qlora', label: 'QLoRA (4-bit, less VRAM)' }, { value: 'lora', label: 'LoRA (16-bit)' }]} />
            <div className="sm:col-span-2">
              <Input
                label="Base model"
                required
                list="training-base-models"
                value={base}
                onChange={(e) => setBase(e.target.value)}
                placeholder="unsloth/Qwen2.5-3B-Instruct"
                hint="A Hugging Face model id; Unsloth-optimised suggestions are listed."
                error={errors.base_model}
              />
              <datalist id="training-base-models">{baseModels.map((m) => <option key={m} value={m} />)}</datalist>
            </div>
            {job && <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value as Status)} options={STATUSES} />}
          </div>
        </FormSection>

        <FormSection title="Hyperparameters">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            {HYPER_FIELDS.map((f) => (
              <Input
                key={f.key}
                label={f.label}
                type="number"
                min={f.min}
                step={f.step}
                value={hp[f.key] ?? ''}
                onChange={(e) => setHp((h) => ({ ...h, [f.key]: e.target.value }))}
                placeholder={String(defaults[f.key])}
                error={errors[f.key]}
              />
            ))}
            <Select label="GGUF export quant" value={hp.quant_export ?? ''} onChange={(e) => setHp((h) => ({ ...h, quant_export: e.target.value }))} options={quantOptions} />
          </div>
          <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">
            Effective batch size: <span className="font-medium tabular-nums text-slate-700 dark:text-slate-300">{(Number(hp.batch_size) || 0) * (Number(hp.grad_accum) || 0)}</span>
          </p>
        </FormSection>

        <FormSection title="Notes">
          <div className="space-y-4">
            <Textarea label="Notes" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="GPU used, cost, eval results…" />
            {job && <Textarea label="Training log" rows={5} className="font-mono text-xs" value={logs} onChange={(e) => setLogs(e.target.value)} placeholder="Paste the trainer output here to keep it with the job." />}
          </div>
        </FormSection>

        {!datasetId && (
          <Alert tone="warning"><CpuChipIcon className="mr-1 inline size-4" aria-hidden />Without a dataset the job can be saved, but no kit can be generated.</Alert>
        )}
      </div>
    </FormModal>
  );
}
