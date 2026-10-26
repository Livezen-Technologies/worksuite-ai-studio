import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BoltIcon, ClipboardDocumentIcon, CpuChipIcon, PencilSquareIcon, PlayIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, CardHeader, CodeBlock, ConfirmDialog, EmptyState, FormModal, Input, Modal, Select,
  StatusBadge, Toggle, useToast,
} from '../../components/ui';
import { del, get, post, put } from '../../lib/api';
import { ago, num } from '../../lib/format';

interface Recipe { key: string; label: string; task: string; needs_gpu: boolean; base_model: string; hyperparams: Record<string, number>; data: 'dataset' | 'prepared'; output: string }
interface Pipeline {
  id: number; name: string; recipe: string; recipe_label: string; dataset_id: number | null; dataset_name: string | null;
  server_id: number | null; server_name: string | null; base_model: string | null; hyperparams: Record<string, number>;
  auto_trigger: number | string; enabled: number | string; min_rows: number | string; max_attempts: number | string;
  last_job_id: number | null; last_triggered_at: string | null; last_status: string | null; last_progress: string | number | null;
  last_error: string | null; pending_change: boolean;
}
interface Node {
  id: number; name: string; status: string; is_enabled: number | string; allow_training: number | string; gpu_model: string | null;
  agent_seen_at: string | null; agent_version: string | null; agent_job_id: number | null; agent_ready: boolean; agent_installed: boolean;
}
interface Overview { pipelines: Pipeline[]; recipes: Recipe[]; nodes: Node[] }
interface Dataset { id: number; name: string; item_count: number | string; rights_status?: string; category?: string | null }

const on = (v: unknown) => v === true || Number(v) === 1;

/**
 * Automated training: pipelines (recipe + data + node) that queue a job when their data changes, and the nodes whose
 * training agent picks those jobs up. Lives at the top of the Training page; jobs it creates appear in the table below.
 */
export default function TrainingPipelines({ canManage }: { canManage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({
    queryKey: ['training', 'pipelines'], queryFn: () => get<Overview>('/training/pipelines'),
    refetchInterval: 15_000,
  });
  const [editing, setEditing] = useState<Pipeline | 'new' | null>(null);
  const [removing, setRemoving] = useState<Pipeline | null>(null);
  const [agent, setAgent] = useState<{ node: Node; command: string } | null>(null);
  const refresh = () => { qc.invalidateQueries({ queryKey: ['training'] }); };

  const run = useMutation({
    mutationFn: (p: Pipeline) => post<{ id: number }>(`/training/pipelines/${p.id}/run`),
    onSuccess: (j) => { toast.success('Run queued', `Job #${j.id} starts on the next free node that can run it.`); refresh(); },
    onError: (e) => { toast.error(e); refresh(); },
  });
  const patch = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, unknown> }) => put(`/training/pipelines/${id}`, body),
    onSuccess: refresh, onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (p: Pipeline) => del(`/training/pipelines/${p.id}`),
    onSuccess: () => { toast.success('Pipeline deleted'); setRemoving(null); refresh(); }, onError: (e) => toast.error(e),
  });
  const allow = useMutation({
    mutationFn: ({ id, value }: { id: number; value: boolean }) => put(`/compute/nodes/${id}`, { allow_training: value }),
    onSuccess: refresh, onError: (e) => toast.error(e),
  });
  const issue = useMutation({
    mutationFn: (n: Node) => post<{ token: string; command: string }>(`/servers/${n.id}/agent-token`).then((r) => ({ node: n, command: r.command })),
    onSuccess: setAgent, onError: (e) => toast.error(e),
  });

  const data = q.data;
  const ready = (data?.nodes ?? []).filter((n) => n.agent_ready && on(n.allow_training) && on(n.is_enabled));

  return (
    <div className="mb-6 grid gap-6 xl:grid-cols-3">
      <Card className="xl:col-span-2">
        <CardHeader
          title="Training pipelines"
          description="A pipeline queues a training run when its data changes; a node's training agent picks it up, trains and sends the model back."
          actions={canManage && <Button size="sm" icon={PlusIcon} onClick={() => setEditing('new')}>New pipeline</Button>}
        />
        {q.isLoading ? <p className="p-6 text-sm text-slate-500">Loading…</p> : !data?.pipelines.length ? (
          <EmptyState icon={BoltIcon} title="No pipelines yet"
            description="Create one to train automatically: pick a recipe and its data, and leave Automatic on."
            action={canManage && <Button icon={PlusIcon} onClick={() => setEditing('new')}>New pipeline</Button>} />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-white/10">
            {data.pipelines.map((p) => (
              <li key={p.id} className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-900 dark:text-white">{p.name}</span>
                    {!on(p.enabled) && <Badge>paused</Badge>}
                    {on(p.enabled) && on(p.auto_trigger) && <Badge tone="green">automatic</Badge>}
                    {p.pending_change && on(p.enabled) && <Badge tone="amber">data changed</Badge>}
                  </div>
                  <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {p.recipe_label} · {p.dataset_name ?? 'prepared data'} · {p.server_name ?? 'any capable node'}
                  </p>
                  <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    {p.last_job_id ? <>Last run #{p.last_job_id} <StatusBadge status={p.last_status ?? 'unknown'} />
                      {p.last_status === 'running' && p.last_progress != null && <span className="tabular-nums">{Number(p.last_progress).toFixed(0)}%</span>}
                      {p.last_triggered_at && <span>{ago(p.last_triggered_at)}</span>}</> : 'Not run yet'}
                  </p>
                  {p.last_error && p.last_status === 'failed' && <p className="mt-1 line-clamp-2 text-xs text-red-700 dark:text-red-400">{p.last_error}</p>}
                </div>
                {canManage && (
                  <div className="flex items-center gap-2">
                    <Toggle checked={on(p.auto_trigger) && on(p.enabled)} label="Automatic"
                      onChange={(v) => patch.mutate({ id: p.id, body: v ? { auto_trigger: true, enabled: true } : { auto_trigger: false } })} />
                    <Button size="sm" icon={PlayIcon} loading={run.isPending && run.variables?.id === p.id}
                      disabled={['preparing', 'queued', 'running'].includes(p.last_status ?? '')} onClick={() => run.mutate(p)}>Run now</Button>
                    <ActionMenu actions={[
                      { label: 'Edit', icon: PencilSquareIcon, onClick: () => setEditing(p) },
                      { label: on(p.enabled) ? 'Pause' : 'Resume', onClick: () => patch.mutate({ id: p.id, body: { enabled: !on(p.enabled) } }) },
                      { label: 'Delete', icon: TrashIcon, danger: true, onClick: () => setRemoving(p) },
                    ]} />
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="Training nodes" description={`${ready.length} ready to take a job`} />
        <ul className="divide-y divide-slate-200 dark:divide-white/10">
          {(data?.nodes ?? []).map((n) => (
            <li key={n.id} className="px-4 py-3 sm:px-6">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{n.name}</p>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">{n.gpu_model?.split(',')[0] || 'CPU-only'}</p>
                  <div className="mt-1 flex flex-wrap gap-1.5">
                    {n.agent_job_id ? <Badge tone="blue">training job #{n.agent_job_id}</Badge>
                      : n.agent_ready ? <Badge tone="green">agent ready</Badge>
                      : n.agent_installed ? <Badge tone="amber">agent {n.agent_seen_at ? `last seen ${ago(n.agent_seen_at)}` : 'not connected'}</Badge>
                      : <Badge>no agent</Badge>}
                    {!on(n.is_enabled) && <Badge tone="amber">node disabled</Badge>}
                  </div>
                </div>
                {canManage && (
                  <div className="flex shrink-0 flex-col items-end gap-2">
                    <Toggle checked={on(n.allow_training)} label="Training" onChange={(v) => allow.mutate({ id: n.id, value: v })} />
                    <Button size="sm" variant="secondary" icon={CpuChipIcon} loading={issue.isPending && issue.variables?.id === n.id}
                      onClick={() => issue.mutate(n)}>{n.agent_installed ? 'Reinstall agent' : 'Install agent'}</Button>
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      </Card>

      {editing && data && (
        <PipelineForm pipeline={editing === 'new' ? null : editing} recipes={data.recipes} nodes={data.nodes}
          onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh(); }} />
      )}
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => { if (removing) remove.mutate(removing); }}
        title={`Delete “${removing?.name}”?`} message="Its past jobs stay on the Training page." confirmLabel="Delete" busy={remove.isPending} tone="danger" />
      <Modal open={!!agent} onClose={() => setAgent(null)} title={`Install the training agent on ${agent?.node.name}`}
        description="Run this on the node as your normal user (not root). It asks for sudo once so the agent keeps running after you log out.">
        {agent && (
          <div className="space-y-3">
            <Alert tone="warning" title="Shown once">The command carries this node's agent token; it replaces any earlier one.</Alert>
            <CodeBlock>{agent.command}</CodeBlock>
            <Button size="sm" variant="secondary" icon={ClipboardDocumentIcon}
              onClick={() => navigator.clipboard.writeText(agent.command).then(() => toast.success('Copied'))}>Copy command</Button>
            <p className="text-sm text-slate-600 dark:text-slate-400">
              Needs Linux with systemd, <code>python3-venv</code> and, for GPU recipes, the NVIDIA driver. Then switch <b>Training</b> on for the node.
            </p>
          </div>
        )}
      </Modal>
    </div>
  );
}

function PipelineForm({ pipeline, recipes, nodes, onClose, onSaved }: {
  pipeline: Pipeline | null; recipes: Recipe[]; nodes: Node[]; onClose: () => void; onSaved: () => void;
}) {
  const toast = useToast();
  const [name, setName] = useState(pipeline?.name ?? '');
  const [recipe, setRecipe] = useState(pipeline?.recipe ?? recipes[0]?.key ?? 'text_sft');
  const [datasetId, setDatasetId] = useState(String(pipeline?.dataset_id ?? ''));
  const [serverId, setServerId] = useState(String(pipeline?.server_id ?? ''));
  const [base, setBase] = useState(pipeline?.base_model ?? '');
  const [auto, setAuto] = useState(pipeline ? on(pipeline.auto_trigger) : true);
  const [minRows, setMinRows] = useState(String(pipeline?.min_rows ?? 200));
  const r = recipes.find((x) => x.key === recipe);
  const [hp, setHp] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(pipeline?.hyperparams ?? {}).map(([k, v]) => [k, String(v)])));
  const datasets = useQuery({ queryKey: ['datasets'], queryFn: () => get<Dataset[]>('/datasets'), enabled: r?.data === 'dataset' });
  const usable = (datasets.data ?? []).filter((d) => d.rights_status === 'approved' && !['speech', 'evaluation', 'reference'].includes(d.category ?? ''));
  const capable = nodes.filter((n) => !r?.needs_gpu || !!n.gpu_model);

  const save = useMutation({
    mutationFn: () => {
      const body = {
        name, recipe, dataset_id: r?.data === 'dataset' ? Number(datasetId) || null : null, server_id: Number(serverId) || null,
        base_model: base.trim() || null, auto_trigger: auto, min_rows: Number(minRows) || 200,
        hyperparams: Object.fromEntries(Object.entries(hp).filter(([, v]) => v.trim() !== '').map(([k, v]) => [k, Number(v)])),
      };
      return pipeline ? put(`/training/pipelines/${pipeline.id}`, body) : post('/training/pipelines', body);
    },
    onSuccess: () => { toast.success(pipeline ? 'Pipeline saved' : 'Pipeline created'); onSaved(); },
    onError: (e) => toast.error(e),
  });

  return (
    <FormModal open onClose={onClose} size="lg" title={pipeline ? `Edit ${pipeline.name}` : 'New training pipeline'}
      description="When Automatic is on, a run is queued whenever the data changes (and once at the start)."
      submitLabel={pipeline ? 'Save' : 'Create pipeline'} submitting={save.isPending} onSubmit={() => save.mutate()}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="WorkSuite Sinhala chat — v2 mix" wrapperClassName="sm:col-span-2" />
        <Select label="Recipe" value={recipe} onChange={(e) => { setRecipe(e.target.value); setHp({}); }}
          options={recipes.map((x) => ({ value: x.key, label: x.label }))} hint={r ? `Output: ${r.output}${r.needs_gpu ? ' · needs an NVIDIA GPU' : ''}` : undefined} />
        {r?.data === 'dataset' ? (
          <Select label="Dataset" required value={datasetId} onChange={(e) => setDatasetId(e.target.value)} placeholder={datasets.isLoading ? 'Loading…' : 'Choose a dataset'}
            options={usable.map((d) => ({ value: String(d.id), label: `${d.name} (${num(d.item_count)} rows)` }))}
            hint="Rights-approved text datasets; a change to it starts a new run." />
        ) : (
          <Input label="Data" value="Prepared on the server (recipe data)" disabled hint="A change to the prepared files starts a new run." />
        )}
        <Select label="Node" value={serverId} onChange={(e) => setServerId(e.target.value)}
          options={[{ value: '', label: 'Any capable node' }, ...capable.map((n) => ({ value: String(n.id), label: n.name }))]} />
        <Input label="Base model" value={base} onChange={(e) => setBase(e.target.value)} placeholder={r?.base_model} hint="Hugging Face id; empty uses the recipe default." />
        {r && Object.entries(r.hyperparams).map(([k, v]) => (
          <Input key={k} label={k.replace(/_/g, ' ')} type="number" step="any" value={hp[k] ?? ''} placeholder={String(v)}
            onChange={(e) => setHp((h) => ({ ...h, [k]: e.target.value }))} />
        ))}
        {r?.data === 'dataset' && <Input label="Minimum training rows" type="number" value={minRows} onChange={(e) => setMinRows(e.target.value)} />}
        <div className="sm:col-span-2"><Toggle checked={auto} onChange={setAuto} label="Automatic" description="Queue a run whenever the data changes." /></div>
      </div>
    </FormModal>
  );
}
