import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowPathIcon, CloudArrowDownIcon, MagnifyingGlassIcon, PencilSquareIcon, PlusIcon, RectangleStackIcon, TrashIcon, XMarkIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, CardHeader, Checkbox, ConfirmDialog, DataTable, EmptyState, FormModal, FormSection, Input,
  SearchInput, Select, Toggle, Toolbar, useToast, type BadgeTone, type Column,
} from '../../components/ui';
import { ApiError, del, get, post, put } from '../../lib/api';
import { compact, num } from '../../lib/format';
import { useAuth } from '../../lib/auth';
import type { Server } from '../Servers/shared';

type ModelType = 'llm' | 'embedding' | 'stt' | 'tts' | 'vision' | 'multimodal';
type DepStatus = 'pending' | 'deploying' | 'deployed' | 'loaded' | 'failed' | 'removing';

interface Deployment { server_id: number; server: string; kind: string; server_status: string; status: DepStatus; error: string | null }
interface Perf { requests: number; errors: number; error_rate: number; avg_ms: number | null; tokens: number; tokens_per_sec: number | null }
export interface RegistryModel {
  id: number; name: string; display_name: string | null; type: ModelType; version: string | null; family: string | null;
  parameter_size: string | null; quantization: string | null; vram_gb: number | null; context_length: number | null;
  capabilities: string[]; engine: string; source: string | null; description: string | null; access_roles: string[] | null;
  keep_loaded: boolean; idle_unload_minutes: number | null; enabled: boolean;
  status: 'registered' | 'pending' | 'deploying' | 'deployed' | 'loaded' | 'failed' | 'disabled';
  deployments: Deployment[]; performance: Perf | null;
}
interface Meta { types: ModelType[]; capabilities: string[]; tasks: string[]; roles: string[] }

export const TYPE_LABEL: Record<ModelType, string> = {
  llm: 'LLM', embedding: 'Embedding', stt: 'Speech-to-text', tts: 'Text-to-speech', vision: 'Vision', multimodal: 'Multimodal',
};
const TYPE_TONE: Record<ModelType, BadgeTone> = { llm: 'indigo', embedding: 'blue', stt: 'purple', tts: 'purple', vision: 'green', multimodal: 'amber' };
const DEP_TONE: Record<DepStatus, BadgeTone> = { pending: 'gray', deploying: 'blue', deployed: 'green', loaded: 'green', failed: 'red', removing: 'amber' };
const STATUS_TONE: Record<RegistryModel['status'], BadgeTone> = {
  registered: 'gray', pending: 'gray', deploying: 'blue', deployed: 'green', loaded: 'green', failed: 'red', disabled: 'gray',
};
const QUANTS = ['', 'Q2_K', 'Q3_K_M', 'Q4_0', 'Q4_K_M', 'Q5_K_M', 'Q6_K', 'Q8_0', 'F16', 'BF16', 'AWQ', 'GPTQ', 'FP8'];
const ENGINES = [
  { value: 'ollama', label: 'Ollama' }, { value: 'vllm', label: 'vLLM' }, { value: 'llamacpp', label: 'llama.cpp' },
  { value: 'openai_compatible', label: 'OpenAI-compatible' }, { value: 'cloud', label: 'Hosted API' }, { value: 'any', label: 'Any engine' },
];

const toggleIn = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

// ---- Edit / register ---------------------------------------------------------------

interface Form {
  name: string; display_name: string; type: ModelType; version: string; family: string; parameter_size: string; quantization: string;
  vram_gb: string; context_length: string; capabilities: string[]; engine: string; source: string; description: string;
  access_roles: string[]; keep_loaded: boolean; idle_unload_minutes: string; enabled: boolean; server_ids: number[];
}
const toForm = (m: RegistryModel | null): Form => ({
  name: m?.name ?? '', display_name: m?.display_name ?? '', type: m?.type ?? 'llm', version: m?.version ?? '', family: m?.family ?? '',
  parameter_size: m?.parameter_size ?? '', quantization: m?.quantization ?? '', vram_gb: m?.vram_gb?.toString() ?? '',
  context_length: m?.context_length?.toString() ?? '', capabilities: m?.capabilities ?? ['chat', 'completion'], engine: m?.engine ?? 'ollama',
  source: m?.source ?? '', description: m?.description ?? '', access_roles: m?.access_roles ?? [], keep_loaded: m?.keep_loaded ?? false,
  idle_unload_minutes: m?.idle_unload_minutes?.toString() ?? '', enabled: m?.enabled ?? true, server_ids: [],
});

function ModelForm({ model, meta, servers, onClose }: { model: RegistryModel | null; meta?: Meta; servers: Server[]; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [f, setF] = useState<Form>(() => toForm(model));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((p) => ({ ...p, [k]: v }));
  const nodes = servers.filter((s) => !['openai', 'anthropic', 'gemini'].includes(s.kind ?? 'ollama'));

  const save = useMutation({
    mutationFn: () => {
      const { server_ids, name, ...rest } = f;
      return model ? put<RegistryModel>(`/registry/${model.id}`, rest) : post<RegistryModel>('/registry', { ...rest, name: name.trim(), server_ids });
    },
    onSuccess: (_r) => { toast.success(model ? 'Model updated' : 'Model registered'); qc.invalidateQueries({ queryKey: ['registry'] }); qc.invalidateQueries({ queryKey: ['models'] }); onClose(); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });

  return (
    <FormModal open onClose={onClose} size="lg" title={model ? `Edit ${model.name}` : 'Register a model'}
      description={model ? undefined : 'Add a model to the registry, and optionally deploy it to nodes now. Type and capabilities are guessed from the name; adjust them as needed.'}
      submitLabel={model ? 'Save' : 'Register'} submitting={save.isPending}
      onSubmit={() => { if (!model && !f.name.trim()) { setErrors({ name: 'Required.' }); return; } save.mutate(); }}>
      <div className="space-y-6">
        <FormSection title="Model">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input label="Model id" required disabled={!!model} value={f.name} onChange={(e) => set('name', e.target.value)} error={errors.name}
              placeholder="llama3.1:8b" hint={model ? 'The id the engine knows the model by.' : 'As the engine names it: an Ollama tag, or a Hugging Face id for vLLM.'} />
            <Input label="Display name" value={f.display_name} onChange={(e) => set('display_name', e.target.value)} placeholder="Optional" />
            <Select label="Type" value={f.type} onChange={(e) => set('type', e.target.value as ModelType)}
              options={(meta?.types ?? Object.keys(TYPE_LABEL) as ModelType[]).map((t) => ({ value: t, label: TYPE_LABEL[t] }))} />
            <Select label="Engine" value={f.engine} onChange={(e) => set('engine', e.target.value)} options={ENGINES} />
            <Input label="Version" value={f.version} onChange={(e) => set('version', e.target.value)} placeholder="e.g. 8b, v1.2, 2024-11" />
            <Input label="Family" value={f.family} onChange={(e) => set('family', e.target.value)} placeholder="llama" />
            <Input label="Parameters" value={f.parameter_size} onChange={(e) => set('parameter_size', e.target.value)} placeholder="8B" />
            <Select label="Quantization" value={f.quantization} onChange={(e) => set('quantization', e.target.value)}
              options={Array.from(new Set([...QUANTS, f.quantization])).map((q) => ({ value: q, label: q || 'Not set' }))} />
            <Input label="GPU memory needed (GB)" type="number" min={0} step="0.1" value={f.vram_gb} onChange={(e) => set('vram_gb', e.target.value)}
              hint="Nodes with less total VRAM are refused at deploy time." />
            <Input label="Max context (tokens)" type="number" min={0} value={f.context_length} onChange={(e) => set('context_length', e.target.value)} placeholder="8192" />
          </div>
          <Input label="Source" value={f.source} onChange={(e) => set('source', e.target.value)} placeholder="ollama.com/library/llama3.1 or a Hugging Face URL" />
          <Input label="Description" value={f.description} onChange={(e) => set('description', e.target.value)} />
        </FormSection>
        <FormSection title="Capabilities">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {(meta?.capabilities ?? []).map((c) => <Checkbox key={c} checked={f.capabilities.includes(c)} onChange={() => set('capabilities', toggleIn(f.capabilities, c))} label={c.replace('_', ' ')} />)}
          </div>
        </FormSection>
        <FormSection title="Access" description="Which roles may use this model in chat and through the API gateway. None selected means everyone; admins always can.">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {(meta?.roles ?? []).filter((r) => !['super_admin', 'admin'].includes(r)).map((r) => (
              <Checkbox key={r} checked={f.access_roles.includes(r)} onChange={() => set('access_roles', toggleIn(f.access_roles, r))} label={r.replace('_', ' ')} />
            ))}
          </div>
          <Toggle checked={f.enabled} onChange={(v) => set('enabled', v)} label="Enabled" description="A disabled model is refused for everyone." />
        </FormSection>
        <FormSection title="Loading" description="Applied every 5 minutes on Ollama nodes that have the model.">
          <Toggle checked={f.keep_loaded} onChange={(v) => set('keep_loaded', v)} label="Keep loaded" description="Load into memory automatically and keep it resident, so the first request is fast." />
          {!f.keep_loaded && <Input label="Unload after idle (minutes)" type="number" min={0} value={f.idle_unload_minutes} onChange={(e) => set('idle_unload_minutes', e.target.value)}
            placeholder="Engine default" hint="Free GPU memory when the model has not been used for this long." />}
        </FormSection>
        {!model && nodes.length > 0 && (
          <FormSection title="Deploy now" description="Optional. Ollama nodes download the model; other engines must already serve it.">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {nodes.map((s) => <Checkbox key={s.id} checked={f.server_ids.includes(s.id)} onChange={() => set('server_ids', f.server_ids.includes(s.id) ? f.server_ids.filter((x) => x !== s.id) : [...f.server_ids, s.id])}
                label={<span>{s.name}<span className="block text-xs text-slate-500 dark:text-slate-400">{s.gpu_vram_gb ? `${Number(s.gpu_vram_gb)} GB VRAM` : 'VRAM unknown'} · {s.status}</span></span>} />)}
            </div>
          </FormSection>
        )}
      </div>
    </FormModal>
  );
}

// ---- Deploy ------------------------------------------------------------------------------

function DeployModal({ model, servers, onClose }: { model: RegistryModel; servers: Server[]; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const assigned = new Set(model.deployments.map((d) => d.server_id));
  const nodes = servers.filter((s) => !['openai', 'anthropic', 'gemini'].includes(s.kind ?? 'ollama') && !assigned.has(s.id));
  const [ids, setIds] = useState<number[]>([]);
  const [force, setForce] = useState(false);
  const fits = (s: Server) => !model.vram_gb || !s.gpu_vram_gb || Number(s.gpu_vram_gb) >= model.vram_gb;
  const go = useMutation({
    mutationFn: () => post<{ queued: string[]; recorded: string[]; skipped: Record<string, string> }>(`/registry/${model.id}/deploy`, { server_ids: ids, force }),
    onSuccess: (r) => {
      const skipped = Object.entries(r.skipped ?? {});
      toast.success('Deployment started', skipped.length ? `Skipped: ${skipped.map(([n, w]) => `${n} (${w})`).join('; ')}` : undefined);
      qc.invalidateQueries({ queryKey: ['registry'] });
      onClose();
    },
    onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={`Deploy ${model.name}`} submitLabel="Deploy" submitting={go.isPending}
      description={model.vram_gb ? `Needs about ${model.vram_gb} GB of GPU memory.` : 'Set the GPU memory it needs (Edit) to have placement checked.'}
      onSubmit={() => (ids.length ? go.mutate() : toast.error('Choose at least one node.'))}>
      {nodes.length ? (
        <div className="space-y-3">
          {nodes.map((s) => {
            const free = s.gpu_vram_gb && s.vram_used_gb != null ? Number(s.gpu_vram_gb) - Number(s.vram_used_gb) : null;
            return (
              <Checkbox key={s.id} checked={ids.includes(s.id)} onChange={() => setIds((l) => (l.includes(s.id) ? l.filter((x) => x !== s.id) : [...l, s.id]))}
                label={<span>{s.name} {!fits(s) && <Badge tone="red">Not enough VRAM</Badge>}
                  <span className="block text-xs text-slate-500 dark:text-slate-400">
                    {(s.kind ?? 'ollama') === 'ollama' ? 'Ollama — downloads the model' : 'Serves its own models — assignment only'} · {s.gpu_vram_gb ? `${Number(s.gpu_vram_gb)} GB VRAM${free != null ? `, ${free.toFixed(1)} GB free now` : ''}` : 'VRAM unknown'} · {s.status}
                  </span></span>} />
            );
          })}
          {ids.some((id) => !fits(nodes.find((s) => s.id === id)!)) && <Toggle checked={force} onChange={setForce} label="Deploy anyway" description="Skip the VRAM check, e.g. for CPU offload." />}
        </div>
      ) : <Alert tone="info">Every self-hosted node already has this model assigned.</Alert>}
    </FormModal>
  );
}

// ---- Defaults ----------------------------------------------------------------------------

interface DefaultRow { task: string; application: string; model_name: string }

function Defaults({ models, meta, manage }: { models: RegistryModel[]; meta?: Meta; manage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({ queryKey: ['registry', 'defaults'], queryFn: () => get<DefaultRow[]>('/registry/defaults') });
  const [rows, setRows] = useState<DefaultRow[] | null>(null);
  const data = rows ?? q.data ?? [];
  const save = useMutation({
    mutationFn: () => put<DefaultRow[]>('/registry/defaults', { defaults: data }),
    onSuccess: (r) => { setRows(null); qc.setQueryData(['registry', 'defaults'], r); toast.success('Default models saved'); },
    onError: (e) => toast.error(e),
  });
  const upd = (i: number, k: keyof DefaultRow, v: string) => setRows(data.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  const options = [{ value: '', label: 'Choose a model' }, ...models.filter((m) => m.enabled).map((m) => ({ value: m.name, label: `${m.display_name || m.name} (${TYPE_LABEL[m.type]})` }))];
  return (
    <Card>
      <CardHeader title="Default models" description="Used when a chat or API request names no model. A row for an application (the API key's application name) wins over the row for every application."
        actions={manage && <div className="flex gap-2">
          <Button size="sm" icon={PlusIcon} onClick={() => setRows([...data, { task: 'chat', application: '', model_name: '' }])}>Add</Button>
          {rows && <Button size="sm" variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Save</Button>}
        </div>} />
      <div className="space-y-2 p-4 sm:px-6">
        {!data.length && <p className="text-sm text-slate-500 dark:text-slate-400">No defaults yet. Requests must name a model.</p>}
        {data.map((r, i) => (
          <div key={i} className="grid grid-cols-1 items-end gap-2 sm:grid-cols-[10rem_1fr_1fr_auto]">
            <Select aria-label="Task" disabled={!manage} value={r.task} onChange={(e) => upd(i, 'task', e.target.value)} options={(meta?.tasks ?? ['chat']).map((t) => ({ value: t, label: t }))} />
            <Input aria-label="Application" disabled={!manage} value={r.application} onChange={(e) => upd(i, 'application', e.target.value)} placeholder="Every application" />
            <Select aria-label="Model" disabled={!manage} value={r.model_name} onChange={(e) => upd(i, 'model_name', e.target.value)}
              options={options.some((o) => o.value === r.model_name) ? options : [...options, { value: r.model_name, label: r.model_name }]} />
            {manage && <Button size="sm" variant="ghost" icon={XMarkIcon} aria-label="Remove" onClick={() => setRows(data.filter((_, j) => j !== i))} />}
          </div>
        ))}
      </div>
    </Card>
  );
}

// ---- Registry ----------------------------------------------------------------------------

export default function Registry() {
  const qc = useQueryClient();
  const toast = useToast();
  const manage = useAuth().can('models.manage');
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [editing, setEditing] = useState<RegistryModel | null | undefined>(undefined);
  const [deploying, setDeploying] = useState<RegistryModel | null>(null);
  const [removing, setRemoving] = useState<RegistryModel | null>(null);
  const [undeploying, setUndeploying] = useState<{ m: RegistryModel; d: Deployment } | null>(null);

  const list = useQuery({ queryKey: ['registry'], queryFn: () => get<RegistryModel[]>('/registry'), refetchInterval: (q) => (q.state.data?.some((m) => m.status === 'deploying') ? 5000 : 60_000) });
  const meta = useQuery({ queryKey: ['registry', 'meta'], queryFn: () => get<Meta>('/registry/meta') });
  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers') });
  const refresh = () => { qc.invalidateQueries({ queryKey: ['registry'] }); qc.invalidateQueries({ queryKey: ['models'] }); };

  const discover = useMutation({ mutationFn: () => post<{ added: number }>('/registry/discover'), onSuccess: (r) => { toast.success(r.added ? `Registered ${r.added} model${r.added > 1 ? 's' : ''}` : 'Nothing new to register'); refresh(); }, onError: (e) => toast.error(e) });
  const update = useMutation({ mutationFn: (id: number) => post(`/registry/${id}/update`), onSuccess: () => { toast.success('Update queued', 'Each Ollama node downloads the latest version of the tag.'); refresh(); }, onError: (e) => toast.error(e) });
  const remove = useMutation({ mutationFn: (id: number) => del(`/registry/${id}`), onSuccess: () => { toast.success('Removed from the registry'); setRemoving(null); refresh(); }, onError: (e) => toast.error(e) });
  const undeploy = useMutation({ mutationFn: ({ m, d }: { m: RegistryModel; d: Deployment }) => del(`/registry/${m.id}/deploy/${d.server_id}`), onSuccess: () => { toast.success('Removed from the node'); setUndeploying(null); refresh(); }, onError: (e) => toast.error(e) });

  const q = search.trim().toLowerCase();
  const rows = useMemo(() => (list.data ?? []).filter((m) => (!type || m.type === type) && (!q || [m.name, m.display_name, m.family].some((v) => v?.toLowerCase().includes(q)))), [list.data, q, type]);

  const columns: Column<RegistryModel>[] = [
    { key: 'name', header: 'Model', cell: (m) => (
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="font-mono font-medium text-slate-900 dark:text-white">{m.display_name || m.name}</span>
          <Badge tone={TYPE_TONE[m.type]}>{TYPE_LABEL[m.type]}</Badge>
          {m.access_roles?.length ? <Badge tone="amber">Restricted</Badge> : null}
          {m.keep_loaded && <Badge tone="gray">Kept loaded</Badge>}
        </div>
        <div className="text-xs text-slate-500 dark:text-slate-400">
          {[m.display_name ? m.name : null, m.version && `v ${m.version}`, m.parameter_size, m.quantization, m.context_length && `${num(m.context_length)} ctx`].filter(Boolean).join(' · ') || '—'}
        </div>
        {m.capabilities.length > 0 && <div className="mt-1 flex flex-wrap gap-1">{m.capabilities.map((c) => <span key={c} className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600 dark:bg-white/10 dark:text-slate-300">{c.replace('_', ' ')}</span>)}</div>}
      </div>
    ) },
    { key: 'vram', header: 'GPU memory', hideBelow: 'md', cell: (m) => <span className="text-sm">{m.vram_gb ? `${m.vram_gb} GB` : '—'}</span> },
    { key: 'deployments', header: 'Nodes', cell: (m) => (
      <div className="flex max-w-xs flex-wrap gap-1">
        {m.deployments.length ? m.deployments.map((d) => (
          <span key={d.server_id} title={d.error ?? `${d.server}: ${d.status}`} className="inline-flex items-center gap-0.5">
            <Badge tone={DEP_TONE[d.status]}>{d.server} · {d.status}</Badge>
            {manage && <button type="button" aria-label={`Remove from ${d.server}`} className="rounded p-0.5 text-slate-400 hover:text-red-500" onClick={() => setUndeploying({ m, d })}><XMarkIcon className="size-3" /></button>}
          </span>
        )) : <span className="text-xs text-slate-500 dark:text-slate-400">Not deployed</span>}
      </div>
    ) },
    { key: 'perf', header: '24 h performance', hideBelow: 'lg', cell: (m) => m.performance ? (
      <div className="text-xs text-slate-600 dark:text-slate-300">
        <div>{num(m.performance.requests)} req · {compact(m.performance.tokens)} tokens</div>
        <div className="text-slate-500 dark:text-slate-400">{m.performance.avg_ms != null ? `${num(m.performance.avg_ms)} ms avg` : '—'}{m.performance.tokens_per_sec ? ` · ${m.performance.tokens_per_sec} tok/s` : ''}
          {m.performance.errors ? <span className="text-red-600 dark:text-red-400"> · {m.performance.error_rate}% errors</span> : null}</div>
      </div>
    ) : <span className="text-xs text-slate-400 dark:text-slate-500">No traffic</span> },
    { key: 'status', header: 'Status', cell: (m) => <Badge tone={STATUS_TONE[m.status]}>{m.status}</Badge> },
    ...(manage ? [{ key: 'actions', header: '', align: 'right' as const, cell: (m: RegistryModel) => (
      <ActionMenu actions={[
        { label: 'Deploy to nodes', icon: CloudArrowDownIcon, onClick: () => setDeploying(m), disabled: !m.enabled },
        { label: 'Update on all nodes', icon: ArrowPathIcon, onClick: () => update.mutate(m.id), hidden: !m.deployments.some((d) => d.kind === 'ollama') },
        { label: 'Edit', icon: PencilSquareIcon, onClick: () => setEditing(m) },
        { label: 'Remove from registry', icon: TrashIcon, danger: true, onClick: () => setRemoving(m) },
      ]} />
    ) }] : []),
  ];

  return (
    <div className="space-y-6">
      <Card>
        <Toolbar right={manage && <div className="flex gap-2">
          <Button size="sm" icon={MagnifyingGlassIcon} loading={discover.isPending} onClick={() => discover.mutate()}>Discover installed</Button>
          <Button size="sm" variant="primary" icon={PlusIcon} onClick={() => setEditing(null)}>Register model</Button>
        </div>}>
          <SearchInput value={search} onChange={setSearch} placeholder="Search the registry…" className="w-full sm:w-64" />
          <Select aria-label="Type" value={type} onChange={(e) => setType(e.target.value)} wrapperClassName="w-full sm:w-48"
            options={[{ value: '', label: 'All types' }, ...Object.entries(TYPE_LABEL).map(([value, label]) => ({ value, label }))]} />
        </Toolbar>
        <DataTable columns={columns} rows={list.data ? rows : undefined} rowKey={(m) => m.id} loading={list.isLoading} error={list.error} onRetry={() => list.refetch()}
          empty={<EmptyState icon={RectangleStackIcon} title="The registry is empty"
            description="Register models to manage their type, version, GPU memory, access and placement in one place. Discover adds every model already on your nodes."
            action={manage && <Button icon={MagnifyingGlassIcon} loading={discover.isPending} onClick={() => discover.mutate()}>Discover installed models</Button>} />} />
      </Card>
      <Defaults models={list.data ?? []} meta={meta.data} manage={manage} />

      {editing !== undefined && <ModelForm model={editing} meta={meta.data} servers={servers.data ?? []} onClose={() => setEditing(undefined)} />}
      {deploying && <DeployModal model={deploying} servers={servers.data ?? []} onClose={() => setDeploying(null)} />}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} busy={remove.isPending} onConfirm={() => removing && remove.mutate(removing.id)}
        title="Remove from the registry?" message={`“${removing?.name}” leaves the registry with its settings and assignments. Copies on nodes are not deleted.`} confirmLabel="Remove" />
      <ConfirmDialog open={!!undeploying} onClose={() => setUndeploying(null)} busy={undeploy.isPending} onConfirm={() => undeploying && undeploy.mutate(undeploying)}
        title={`Remove from ${undeploying?.d.server}?`} confirmLabel="Remove"
        message={undeploying?.d.kind === 'ollama' ? `“${undeploying?.m.name}” is deleted from ${undeploying?.d.server}.` : `The assignment is removed. ${undeploying?.d.server} keeps serving whatever its engine is configured with.`} />
    </div>
  );
}
