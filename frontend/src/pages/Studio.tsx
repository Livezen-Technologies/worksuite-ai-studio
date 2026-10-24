import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowUpTrayIcon, CpuChipIcon, DocumentDuplicateIcon, SparklesIcon, StopIcon, WrenchScrewdriverIcon, XMarkIcon,
} from '@heroicons/react/24/outline';
import {
  Alert, Badge, Button, Card, CardHeader, CodeBlock, EmptyState, ErrorState, Input, Meter, PageHeader, Segmented,
  Select, Skeleton, Textarea, useToast, cx,
} from '../components/ui';
import { ApiError, get, post, streamNdjson } from '../lib/api';
import { useAuth } from '../lib/auth';
import { bytes } from '../lib/format';
import { uploadWithProgress } from './SinLama/upload';

interface Server { id: number; name: string; kind?: string; status?: string }
interface NodeModel { id: number; name: string; server_id: number }
interface Preset { key: string; label: string; name: string; system: string; parameters: { temperature?: number; num_ctx?: number } }
interface Blob { digest: string; filename: string; size: number }
interface Upload { file: File; progress: number; blob?: Blob; error?: string }
type Mode = 'customize' | 'import';

const NAME_RE = /^[a-z0-9][a-z0-9._/-]*(:[a-z0-9._-]+)?$/i;
const QUANTS = ['q4_K_M', 'q4_K_S', 'q8_0'];

export default function Studio() {
  const toast = useToast();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canManage = can('models.manage');

  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers') });
  const presets = useQuery({ queryKey: ['studio', 'presets'], queryFn: () => get<Preset[]>('/studio/presets') });
  const nodes = useMemo(() => (servers.data ?? []).filter((s) => (s.kind ?? 'ollama') === 'ollama'), [servers.data]);

  const [mode, setMode] = useState<Mode>('customize');
  const [serverId, setServerId] = useState('');
  const [name, setName] = useState('');
  const [from, setFrom] = useState('');
  const [system, setSystem] = useState('');
  const [template, setTemplate] = useState('');
  const [temperature, setTemperature] = useState('');
  const [numCtx, setNumCtx] = useState('');
  const [quantize, setQuantize] = useState('');
  const [gguf, setGguf] = useState<Upload | null>(null);
  const [adapter, setAdapter] = useState<Upload | null>(null);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [building, setBuilding] = useState(false);
  const [log, setLog] = useState<string[]>([]);
  const [progress, setProgress] = useState<{ completed: number; total: number } | null>(null);
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);
  const abortRef = useRef<AbortController | null>(null);
  const ggufInput = useRef<HTMLInputElement>(null);
  const adapterInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!serverId && nodes.length) setServerId(String(nodes[0].id));
  }, [nodes, serverId]);

  const models = useQuery({
    queryKey: ['models', { server_id: serverId }],
    queryFn: () => get<NodeModel[]>('/models', { server_id: serverId }),
    enabled: Boolean(serverId),
  });

  // Uploads land in the chosen node's blob store, so a different node means uploading again.
  useEffect(() => { setGguf(null); setAdapter(null); setFrom(''); }, [serverId]);

  function applyPreset(p: Preset) {
    setMode('customize');
    if (!name.trim()) setName(p.name);
    setSystem(p.system);
    setTemperature(p.parameters.temperature != null ? String(p.parameters.temperature) : '');
    setNumCtx(p.parameters.num_ctx != null ? String(p.parameters.num_ctx) : '');
    toast.info(`Preset applied: ${p.label}`);
  }

  async function upload(file: File, kind: 'gguf' | 'adapter') {
    const set = kind === 'gguf' ? setGguf : setAdapter;
    if (!serverId) { toast.error('Pick a target node first.'); return; }
    set({ file, progress: 0 });
    const form = new FormData();
    form.append('file', file);
    form.append('server_id', serverId);
    try {
      const { data } = await uploadWithProgress<Blob>('/studio/upload', form, (f) => set((u) => (u ? { ...u, progress: f } : u)));
      set({ file, progress: 1, blob: data });
      toast.success(`${data.filename} uploaded to the node`);
    } catch (e) {
      set({ file, progress: 0, error: e instanceof Error ? e.message : 'Upload failed.' });
      toast.error(e);
    }
  }

  function validate(): boolean {
    const e: Record<string, string> = {};
    if (!serverId) e.server_id = 'Pick a target node first.';
    if (!name.trim()) e.name = 'Give the new model a name.';
    else if (!NAME_RE.test(name.trim())) e.name = 'Letters, numbers, ".", "_", "-" and an optional :tag only.';
    if (mode === 'customize' && !from) e.from = 'Pick a base model.';
    if (mode === 'import' && !gguf?.blob) e.gguf = 'Upload a GGUF file first.';
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  async function build() {
    if (!validate()) return;
    const parameters: Record<string, number> = {};
    if (temperature !== '') parameters.temperature = Number(temperature);
    if (numCtx !== '') parameters.num_ctx = Number(numCtx);
    const body = {
      server_id: Number(serverId),
      name: name.trim(),
      from: mode === 'customize' ? from : undefined,
      system: system.trim() || undefined,
      template: template.trim() || undefined,
      parameters: Object.keys(parameters).length ? parameters : undefined,
      quantize: quantize || undefined,
      files: mode === 'import' && gguf?.blob ? { [gguf.blob.filename]: gguf.blob.digest } : undefined,
      adapters: adapter?.blob ? { [adapter.blob.filename]: adapter.blob.digest } : undefined,
    };
    const ac = new AbortController();
    abortRef.current = ac;
    setBuilding(true); setLog([]); setProgress(null); setResult(null);
    let failed: string | null = null;
    let done = false;
    try {
      await streamNdjson('/studio/create', body, (line) => {
        if (typeof line.error === 'string') { failed = line.error; return; }
        const status = typeof line.status === 'string' ? line.status : '';
        if (typeof line.total === 'number' && line.total > 0) setProgress({ completed: Number(line.completed ?? 0), total: line.total });
        if (status) setLog((l) => (l[l.length - 1] === status ? l : [...l.slice(-199), status]));
        if (status === 'success' && line.done) done = true;
      }, ac.signal);
      if (failed) throw new Error(failed);
      if (!done) throw new Error('The build stream ended without confirming success.');
      setResult({ ok: true, text: `${name.trim()} is ready — it now appears in Chat and the gateway.` });
      toast.success(`Model ${name.trim()} created`);
      qc.invalidateQueries({ queryKey: ['models'] });
    } catch (e) {
      const msg = ac.signal.aborted ? 'Build stopped. The node may still finish it in the background.' : e instanceof Error ? e.message : 'Build failed.';
      setResult({ ok: false, text: msg });
      if (!ac.signal.aborted) toast.error(msg);
    } finally {
      setBuilding(false);
      abortRef.current = null;
    }
  }

  const nodeOptions = nodes.map((s) => ({ value: s.id, label: s.status ? `${s.name} (${s.status})` : s.name }));
  const modelOptions = (models.data ?? []).map((m) => ({ value: m.name, label: m.name }));
  const disabled = building || !canManage;
  const uploading = (u: Upload | null) => u !== null && !u.blob && !u.error;

  return (
    <>
      <PageHeader
        title="Model Studio"
        description="Build SinLama models — a base model plus a Sinhala persona, or import a trained GGUF / LoRA adapter."
      />

      {!canManage && (
        <Alert tone="info" className="mb-6">You can browse presets, but building, uploading and cloning models needs the models.manage permission.</Alert>
      )}

      {servers.isError ? (
        <ErrorState error={servers.error} onRetry={() => servers.refetch()} />
      ) : servers.isLoading ? (
        <Card padded><Skeleton className="h-64" /></Card>
      ) : nodes.length === 0 ? (
        <Card padded>
          <EmptyState
            icon={CpuChipIcon}
            title="Register an Ollama node first"
            description="Custom models are created on an Ollama node. Add one under Servers, then come back."
            action={<Button onClick={() => { window.location.assign('/servers'); }}>Go to Servers</Button>}
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
          <div className="min-w-0 space-y-6 xl:col-span-2">
            <Card>
              <CardHeader
                title="Build a model"
                actions={
                  <Segmented<Mode>
                    label="Build mode"
                    value={mode}
                    onChange={(m) => { setMode(m); setErrors({}); }}
                    options={[{ value: 'customize', label: 'Customize a base model' }, { value: 'import', label: 'Import GGUF' }]}
                  />
                }
              />
              <div className="space-y-5 p-4 sm:p-6">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Select
                    label="Target node"
                    required
                    value={serverId}
                    onChange={(e) => setServerId(e.target.value)}
                    options={nodeOptions}
                    placeholder="Select an Ollama node"
                    error={errors.server_id}
                    disabled={disabled}
                  />
                  <Input
                    label="New model name"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="sinlama or sinlama:7b"
                    error={errors.name}
                    disabled={disabled}
                  />
                </div>

                {mode === 'customize' ? (
                  <Select
                    label="Base model (installed on the node)"
                    required
                    value={from}
                    onChange={(e) => setFrom(e.target.value)}
                    options={modelOptions}
                    placeholder={models.isLoading ? 'Loading models…' : modelOptions.length ? 'Select a model' : 'No models on this node — install one first'}
                    error={errors.from}
                    disabled={disabled || !serverId}
                  />
                ) : (
                  <FilePick
                    label="Trained model (.gguf)"
                    hint="A full GGUF exported by a training kit. Large files are fine — it is streamed into the node's blob store."
                    upload={gguf}
                    error={errors.gguf}
                    disabled={disabled || !serverId}
                    onPick={() => ggufInput.current?.click()}
                    onClear={() => setGguf(null)}
                  />
                )}
                <input ref={ggufInput} type="file" accept=".gguf" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f, 'gguf'); e.target.value = ''; }} />

                <Textarea
                  label="System prompt (persona)"
                  rows={8}
                  value={system}
                  onChange={(e) => setSystem(e.target.value)}
                  placeholder="You are SinLama AI (සින්ලාමා)… — or pick a preset"
                  hint="Leave blank to inherit from the base model."
                  disabled={disabled}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <Input label="Temperature" type="number" min={0} max={2} step={0.05} value={temperature} onChange={(e) => setTemperature(e.target.value)} placeholder="Inherit" disabled={disabled} />
                  <Input label="Context (num_ctx)" type="number" min={512} max={131072} step={1024} value={numCtx} onChange={(e) => setNumCtx(e.target.value)} placeholder="Inherit" disabled={disabled} />
                  <Select label="Quantize (optional)" value={quantize} onChange={(e) => setQuantize(e.target.value)} options={QUANTS.map((q) => ({ value: q, label: q }))} placeholder="Keep as-is" disabled={disabled} />
                </div>

                <div>
                  <button
                    type="button"
                    onClick={() => setShowAdvanced((v) => !v)}
                    className="text-sm font-medium text-brand-600 hover:text-brand-500 dark:text-blue-400 dark:hover:text-blue-300"
                    aria-expanded={showAdvanced}
                  >
                    {showAdvanced ? 'Hide' : 'Show'} advanced: LoRA adapter / chat template
                  </button>
                  {showAdvanced && (
                    <div className="mt-4 space-y-4 rounded-lg border border-slate-200 p-4 dark:border-white/10">
                      <FilePick
                        label="LoRA adapter"
                        hint="An adapter from a training run, applied on top of the base model."
                        upload={adapter}
                        disabled={disabled || !serverId}
                        onPick={() => adapterInput.current?.click()}
                        onClear={() => setAdapter(null)}
                      />
                      <input ref={adapterInput} type="file" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f, 'adapter'); e.target.value = ''; }} />
                      <Textarea label="Chat template (rarely needed)" rows={4} value={template} onChange={(e) => setTemplate(e.target.value)} className="font-mono" placeholder="{{ .System }} {{ .Prompt }}" disabled={disabled} />
                    </div>
                  )}
                </div>

                <div className="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
                  {building && <Button variant="secondary" icon={StopIcon} onClick={() => abortRef.current?.abort()}>Stop</Button>}
                  <Button
                    icon={WrenchScrewdriverIcon}
                    loading={building}
                    disabled={!canManage || uploading(gguf) || uploading(adapter)}
                    onClick={build}
                  >
                    Build model
                  </Button>
                </div>
              </div>
            </Card>

            {(building || log.length > 0 || result) && (
              <Card>
                <CardHeader
                  title="Build progress"
                  actions={building ? <Badge tone="blue" dot>Building</Badge> : result ? <Badge tone={result.ok ? 'green' : 'red'}>{result.ok ? 'Done' : 'Failed'}</Badge> : null}
                />
                <div className="space-y-4 p-4 sm:p-6">
                  {progress && building && <Meter value={progress.completed} max={progress.total} label={`${bytes(progress.completed)} of ${bytes(progress.total)}`} />}
                  {log.length > 0 && <CodeBlock className="max-h-64 overflow-y-auto">{log.join('\n')}</CodeBlock>}
                  {result && <Alert tone={result.ok ? 'success' : 'danger'}>{result.text}</Alert>}
                </div>
              </Card>
            )}
          </div>

          <div className="min-w-0 space-y-6">
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

            <CopyCard nodes={nodeOptions} defaultServer={serverId} canManage={canManage} />

            <Alert tone="info" title="Where do trained models come from?">
              Fine-tune on a GPU with a kit from Training, then import the GGUF (or attach the LoRA adapter) here.
            </Alert>
          </div>
        </div>
      )}
    </>
  );
}

function FilePick({ label, hint, upload, error, disabled, onPick, onClear }: {
  label: string; hint: string; upload: Upload | null; error?: string; disabled?: boolean; onPick: () => void; onClear: () => void;
}) {
  const busy = upload !== null && !upload.blob && !upload.error;
  return (
    <div>
      <p className="text-sm/6 font-medium text-slate-900 dark:text-white">{label}</p>
      <div className={cx('mt-1.5 rounded-lg border border-dashed p-4', error ? 'border-red-400 dark:border-red-500/60' : 'border-slate-300 dark:border-white/15')}>
        {upload ? (
          <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{upload.file.name}</p>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                  {bytes(upload.file.size)}
                  {upload.blob && <> · <span className="font-mono">{upload.blob.digest.slice(0, 19)}…</span></>}
                </p>
              </div>
              {upload.blob ? <Badge tone="green">On node</Badge> : upload.error ? <Badge tone="red">Failed</Badge> : <Badge tone="blue">{Math.round(upload.progress * 100)}%</Badge>}
              {!busy && (
                <button type="button" onClick={onClear} disabled={disabled} className="rounded p-1 text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-200" aria-label="Remove file">
                  <XMarkIcon className="size-4" />
                </button>
              )}
            </div>
            {busy && <Meter value={Math.round(upload.progress * 100)} label={upload.progress >= 1 ? 'Pushing to the node…' : 'Uploading…'} />}
            {upload.error && <p className="text-sm text-red-600 dark:text-red-400">{upload.error}</p>}
          </div>
        ) : (
          <div className="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-slate-500 dark:text-slate-400">{hint}</p>
            <Button variant="secondary" size="sm" icon={ArrowUpTrayIcon} onClick={onPick} disabled={disabled}>Choose file</Button>
          </div>
        )}
      </div>
      {error && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{error}</p>}
    </div>
  );
}

function CopyCard({ nodes, defaultServer, canManage }: { nodes: { value: number; label: string }[]; defaultServer: string; canManage: boolean }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [serverId, setServerId] = useState(defaultServer);
  const [source, setSource] = useState('');
  const [destination, setDestination] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => { if (!serverId && defaultServer) setServerId(defaultServer); }, [defaultServer, serverId]);
  const models = useQuery({
    queryKey: ['models', { server_id: serverId }],
    queryFn: () => get<NodeModel[]>('/models', { server_id: serverId }),
    enabled: Boolean(serverId),
  });
  const copy = useMutation({
    mutationFn: () => post('/studio/copy', { server_id: Number(serverId), source, destination: destination.trim() }),
    onSuccess: () => {
      toast.success(`Cloned ${source} → ${destination.trim()}`);
      setDestination('');
      qc.invalidateQueries({ queryKey: ['models'] });
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });
  function submit() {
    const e: Record<string, string> = {};
    if (!source) e.source = 'Pick the model to clone.';
    if (!destination.trim()) e.destination = 'Name the copy.';
    else if (!NAME_RE.test(destination.trim())) e.destination = 'Letters, numbers, ".", "_", "-" and an optional :tag only.';
    setErrors(e);
    if (!Object.keys(e).length) copy.mutate();
  }
  return (
    <Card>
      <CardHeader title="Clone a model" description="Copy an existing model under a new name on the same node." />
      <div className="space-y-4 p-4 sm:p-6">
        <Select label="Node" value={serverId} onChange={(e) => { setServerId(e.target.value); setSource(''); }} options={nodes} placeholder="Select a node" disabled={!canManage} />
        <Select
          label="Source model"
          value={source}
          onChange={(e) => setSource(e.target.value)}
          options={(models.data ?? []).map((m) => ({ value: m.name, label: m.name }))}
          placeholder={models.isLoading ? 'Loading…' : 'Select a model'}
          error={errors.source}
          disabled={!canManage || !serverId}
        />
        <Input label="New name" value={destination} onChange={(e) => setDestination(e.target.value)} placeholder="sinlama-backup" error={errors.destination} disabled={!canManage} />
        <div className="flex justify-end">
          <Button variant="secondary" icon={DocumentDuplicateIcon} loading={copy.isPending} disabled={!canManage} onClick={submit}>Clone</Button>
        </div>
      </div>
    </Card>
  );
}
