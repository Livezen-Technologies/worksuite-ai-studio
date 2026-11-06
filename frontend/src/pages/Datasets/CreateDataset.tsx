import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowUpTrayIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Button, FormModal, IconButton, Input, Segmented, Select, Textarea, useToast } from '../../components/ui';
import { ApiError } from '../../lib/api';
import { bytes, num } from '../../lib/format';
import { uploadWithProgress } from '../SinLama/upload';
import { ACCEPT, LANGS, type Dataset } from './shared';

export default function CreateDataset({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: number) => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [name, setName] = useState('');
  const [language, setLanguage] = useState('si');
  const [description, setDescription] = useState('');
  const [kind, setKind] = useState<'text' | 'speech'>('text');
  const [file, setFile] = useState<File | null>(null);
  const [progress, setProgress] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  function reset() { setName(''); setLanguage('si'); setDescription(''); setKind('text'); setFile(null); setProgress(null); setErrors({}); }

  async function submit() {
    if (!name.trim()) { setErrors({ name: 'Dataset name is required.' }); return; }
    const form = new FormData();
    form.append('name', name.trim());
    form.append('language', language);
    form.append('description', description.trim());
    if (file && kind === 'text') form.append('file', file);
    if (kind === 'speech' && !file) { setErrors({ file: 'Choose the zip of recordings.' }); return; }
    setBusy(true); setErrors({});
    try {
      if (kind === 'speech') {
        // create the dataset, then send the zip of recordings + transcripts
        // POST /datasets always answers { dataset, imported, skipped }.
        const { data: created } = await uploadWithProgress<{ dataset: Dataset }>('/datasets', form, () => {});
        const id = created.dataset.id;
        const zip = new FormData(); zip.append('file', file!);
        const { data, message } = await uploadWithProgress<{ imported: number; skipped: number; issues: string[] }>(`/datasets/${id}/speech`, zip, setProgress);
        toast.success(message ?? 'Recordings imported', data.issues?.length ? data.issues.join('; ') : undefined);
        qc.invalidateQueries({ queryKey: ['datasets'] });
        reset(); onClose(); onCreated(id);
        return;
      }
      const { data, message } = await uploadWithProgress<{ dataset: Dataset; imported: number; skipped: number }>('/datasets', form, setProgress);
      toast.success(message ?? 'Dataset created', data.skipped ? `${num(data.skipped)} rows skipped (duplicates or empty).` : undefined);
      qc.invalidateQueries({ queryKey: ['datasets'] });
      reset(); onClose();
      onCreated(data.dataset.id);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.fields);
        const created = (e.data as { dataset_id?: number } | undefined)?.dataset_id;
        if (created) { qc.invalidateQueries({ queryKey: ['datasets'] }); reset(); onClose(); onCreated(created); }
      }
      toast.error(e);
    } finally {
      setBusy(false); setProgress(null);
    }
  }

  return (
    <FormModal open={open} onClose={() => { if (!busy) { reset(); onClose(); } }} title="New dataset" submitLabel="Create dataset" submitting={busy} onSubmit={submit}>
      <div className="space-y-4">
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="sinhala-support-v1" error={errors.name} autoFocus />
        <Select label="Language" value={language} onChange={(e) => setLanguage(e.target.value)} options={LANGS} />
        <Textarea label="Description" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        <Segmented label="Kind" value={kind} onChange={(v) => { setKind(v); setFile(null); }}
          options={[{ value: 'text', label: 'Text rows' }, { value: 'speech', label: 'Speech recordings' }]} />
        {kind === 'text' ? <FileField file={file} onFile={setFile} progress={progress} /> : <SpeechZipField file={file} onFile={setFile} progress={progress} error={errors.file} />}
      </div>
    </FormModal>
  );
}

/** A zip of recordings and their transcripts, for a custom speech model. */
function SpeechZipField({ file, onFile, progress, error }: { file: File | null; onFile: (f: File | null) => void; progress: number | null; error?: string }) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <div>
      <p className="text-sm/6 font-medium text-slate-900 dark:text-white">Recordings (zip)</p>
      <div className="mt-1.5 flex items-center gap-3 rounded-lg border border-dashed border-slate-300 p-3 dark:border-white/15">
        <input ref={ref} type="file" accept=".zip" hidden onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
        <Button type="button" variant="secondary" size="sm" icon={ArrowUpTrayIcon} onClick={() => ref.current?.click()}>Choose zip</Button>
        <span className="min-w-0 truncate text-sm text-slate-600 dark:text-slate-400">{file ? `${file.name} · ${bytes(file.size)}` : 'A .zip of audio files with their transcripts'}</span>
      </div>
      {error && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{error}</p>}
      {progress !== null && <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{progress >= 1 ? 'Saving recordings…' : `Uploading ${Math.round(progress * 100)}%`}</p>}
      <div className="mt-1.5 space-y-1 text-sm text-slate-500 dark:text-slate-400">
        <p>Audio: WAV, MP3, M4A, OGG, FLAC, WebM or Opus — one sentence or phrase per file, up to 30 s each.</p>
        <p>Transcripts: a <code>metadata.csv</code> with <code>file,transcript</code> per line, or a <code>.txt</code> beside each file with the same name (<code>clip01.wav</code> + <code>clip01.txt</code>).</p>
        <p>A few hundred recordings make a real difference; at least 50 are needed to train.</p>
      </div>
    </div>
  );
}

function FileField({ file, onFile, progress }: { file: File | null; onFile: (f: File | null) => void; progress: number | null }) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <div>
      <p className="text-sm/6 font-medium text-slate-900 dark:text-white">Import file (optional)</p>
      <div className="mt-1.5 flex items-center gap-3 rounded-lg border border-dashed border-slate-300 p-3 dark:border-white/15">
        <input ref={ref} type="file" accept={ACCEPT} hidden onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
        <Button type="button" variant="secondary" size="sm" icon={ArrowUpTrayIcon} onClick={() => ref.current?.click()}>Choose file</Button>
        <span className="min-w-0 truncate text-sm text-slate-600 dark:text-slate-400">
          {file ? `${file.name} · ${bytes(file.size)}` : 'JSON, JSONL or CSV'}
        </span>
        {file && progress === null && <IconButton label="Remove file" icon={TrashIcon} variant="ghost" size="sm" onClick={() => onFile(null)} />}
      </div>
      {progress !== null && <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{progress >= 1 ? 'Importing rows…' : `Uploading ${Math.round(progress * 100)}%`}</p>}
      <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
        Fields <code>instruction</code>, <code>input</code>, <code>output</code> (aliases question/answer and prompt/response work). Exact duplicates are skipped.
      </p>
    </div>
  );
}
