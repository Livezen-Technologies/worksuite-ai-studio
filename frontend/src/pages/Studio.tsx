import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { CpuChipIcon, StopIcon, WrenchScrewdriverIcon } from '@heroicons/react/24/outline';
import {
  Alert, Button, Card, CardHeader, EmptyState, ErrorState, Input, PageHeader, Segmented,
  Select, Skeleton, Textarea, useToast,
} from '../components/ui';
import { get, streamNdjson } from '../lib/api';
import { useAuth } from '../lib/auth';
import { uploadWithProgress } from './SinLama/upload';
import AdvancedOptions from './Studio/AdvancedOptions';
import BuildProgress from './Studio/BuildProgress';
import CopyCard from './Studio/CopyCard';
import ModelSource from './Studio/ModelSource';
import PresetList from './Studio/PresetList';
import { NAME_RE, QUANTS, type Blob, type Mode, type NodeModel, type Preset, type Server, type Upload } from './Studio/shared';

export default function Studio() {
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canManage = can('models.manage');

  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers') });
  const presets = useQuery({ queryKey: ['studio', 'presets'], queryFn: () => get<Preset[]>('/studio/presets') });
  const nodes = useMemo(() => (servers.data ?? []).filter((s) => (s.kind ?? 'ollama') === 'ollama' && s.is_enabled !== false), [servers.data]);

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
            action={<Button onClick={() => navigate('/servers')}>Go to Servers</Button>}
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

                <ModelSource
                  mode={mode} from={from} setFrom={setFrom} modelOptions={modelOptions} modelsLoading={models.isLoading}
                  gguf={gguf} setGguf={setGguf} errors={errors} disabled={disabled} serverId={serverId}
                  ggufInput={ggufInput} onUpload={(f) => upload(f, 'gguf')}
                />

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

                <AdvancedOptions
                  showAdvanced={showAdvanced} setShowAdvanced={setShowAdvanced} adapter={adapter} setAdapter={setAdapter}
                  template={template} setTemplate={setTemplate} disabled={disabled} serverId={serverId}
                  adapterInput={adapterInput} onUpload={(f) => upload(f, 'adapter')}
                />

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
              <BuildProgress building={building} log={log} progress={progress} result={result} />
            )}
          </div>

          <div className="min-w-0 space-y-6">
            <PresetList presets={presets} building={building} applyPreset={applyPreset} />

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
