import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BeakerIcon } from '@heroicons/react/24/outline';
import { Alert, Button, DescriptionList, FormSection, Input, Modal, Select, useToast } from '../../components/ui';
import { ApiError, put } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { Hash } from './engineDataset';

/** Lineage columns on training_jobs (migration 2026-10-24-000013). */
export interface LineageJob {
  id: number; name: string; base_model: string; dataset_name?: string | null;
  task?: string | null; dataset_manifest_hash?: string | null; dataset_split_hash?: string | null; base_digest?: string | null;
  config_hash?: string | null; code_commit?: string | null; container_digest?: string | null; seed?: number | string | null;
  hardware?: string | null; output_digest?: string | null;
}

const TASKS = ['text', 'asr', 'tts', 'translation', 'transliteration', 'spelling', 'grammar', 'legacy'];
const SHA = /^[a-f0-9]{64}$/;
const FIELDS = ['task', 'base_digest', 'code_commit', 'container_digest', 'hardware', 'output_digest'] as const;

/** S05 — run lineage panel (PUT /engine/training/{id}/lineage). */
export default function TrainingLineage({ job, open, onClose, kitRefusal }: { job: LineageJob | null; open: boolean; onClose: () => void; kitRefusal?: string | null }) {
  return (
    <Modal open={open} onClose={onClose} size="xl" title={job ? `Lineage — ${job.name}` : 'Lineage'} description="What this run was built from, so its output can be reproduced and evaluated.">
      {job && <LineageBody key={job.id} job={job} kitRefusal={kitRefusal} />}
    </Modal>
  );
}

function LineageBody({ job, kitRefusal }: { job: LineageJob; kitRefusal?: string | null }) {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const toast = useToast();
  const qc = useQueryClient();
  const [f, setF] = useState<Record<(typeof FIELDS)[number], string>>(() =>
    Object.fromEntries(FIELDS.map((k) => [k, String(job[k] ?? (k === 'task' ? 'text' : ''))])) as Record<(typeof FIELDS)[number], string>);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [refusal, setRefusal] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => put(`/engine/training/${job.id}/lineage`, Object.fromEntries(FIELDS.filter((k) => f[k].trim() !== '').map((k) => [k, f[k].trim()]))),
    onSuccess: () => { setRefusal(null); toast.success('Lineage recorded'); qc.invalidateQueries({ queryKey: ['training'] }); },
    onError: (e) => { setRefusal(e instanceof ApiError ? e.message : String(e)); toast.error(e); },
  });

  function submit() {
    const e: Record<string, string> = {};
    for (const k of ['base_digest', 'output_digest'] as const) if (f[k].trim() && !SHA.test(f[k].trim())) e[k] = 'Must be a 64-character lowercase sha256 hex digest.';
    setErrors(e);
    if (!Object.keys(e).length) save.mutate();
  }

  const taskOptions = Array.from(new Set([...TASKS, f.task])).map((t) => ({ value: t, label: t }));

  return (
    <div className="space-y-6">
      {kitRefusal && <Alert tone="danger" title="Training kit refused">{kitRefusal}</Alert>}
      <div className="-mx-4 sm:-mx-6">
        <DescriptionList items={[
          { label: 'Base model', value: <>{job.base_model}<span className="block"><Hash value={job.base_digest} label="Base digest" /></span></> },
          { label: 'Dataset', value: job.dataset_name ?? <span className="text-slate-500 dark:text-slate-400">None</span> },
          { label: 'Dataset manifest hash', value: <Hash value={job.dataset_manifest_hash} label="Manifest hash" /> },
          { label: 'Dataset split hash', value: <Hash value={job.dataset_split_hash} label="Split hash" /> },
          { label: 'Config hash', value: <Hash value={job.config_hash} label="Config hash" /> },
          { label: 'Seed', value: job.seed ?? <span className="text-slate-500 dark:text-slate-400">Not recorded</span> },
          { label: 'Hardware', value: job.hardware || <span className="text-slate-500 dark:text-slate-400">Not recorded</span> },
          { label: 'Code commit', value: job.code_commit ? <code className="font-mono text-xs break-all">{job.code_commit}</code> : <span className="text-slate-500 dark:text-slate-400">Not recorded</span> },
          { label: 'Container digest', value: job.container_digest ? <code className="font-mono text-xs break-all">{job.container_digest}</code> : <span className="text-slate-500 dark:text-slate-400">Not recorded</span> },
          { label: 'Output digest', value: <Hash value={job.output_digest} label="Output digest" /> },
        ]} />
      </div>
      <p className="text-xs text-slate-500 dark:text-slate-400">Dataset hashes, config hash and seed are stamped by the server when the kit is generated.</p>

      {canManage && (
        <FormSection title="Record lineage">
          <div className="space-y-4">
            {refusal && <Alert tone="danger" title="The server refused this">{refusal}</Alert>}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select label="Task" value={f.task} onChange={(e) => setF((x) => ({ ...x, task: e.target.value }))} options={taskOptions} />
              <Input label="Hardware" value={f.hardware} onChange={(e) => setF((x) => ({ ...x, hardware: e.target.value }))} placeholder="1× A100 80 GB" />
              <Input label="Base model digest (sha256)" value={f.base_digest} onChange={(e) => setF((x) => ({ ...x, base_digest: e.target.value }))} error={errors.base_digest} className="font-mono" wrapperClassName="sm:col-span-2" />
              <Input label="Code commit" value={f.code_commit} onChange={(e) => setF((x) => ({ ...x, code_commit: e.target.value }))} className="font-mono" />
              <Input label="Container digest" value={f.container_digest} onChange={(e) => setF((x) => ({ ...x, container_digest: e.target.value }))} className="font-mono" placeholder="sha256:…" />
              <Input label="Output digest (sha256)" value={f.output_digest} onChange={(e) => setF((x) => ({ ...x, output_digest: e.target.value }))} error={errors.output_digest} className="font-mono" wrapperClassName="sm:col-span-2" />
            </div>
          </div>
        </FormSection>
      )}

      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Link
          to={`/engine/evaluations?new=1&training_job=${job.id}`}
          className="inline-flex items-center justify-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-900 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 dark:bg-white/10 dark:text-white dark:ring-white/10 dark:hover:bg-white/20"
        >
          <BeakerIcon className="-ml-0.5 size-4" aria-hidden />Create evaluation
        </Link>
        {canManage && <Button variant="primary" loading={save.isPending} onClick={submit}>Record lineage</Button>}
      </div>
    </div>
  );
}
