import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChatBubbleLeftRightIcon, CpuChipIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  Badge, Button, Card, Checkbox, ConfirmDialog, EmptyState, ErrorState, FormModal, IconButton, Input, PageHeader,
  SearchInput, Segmented, Select, Skeleton, Textarea, Toggle, Toolbar, cx, useToast,
} from '../components/ui';
import { ApiError, del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';

interface Agent {
  id: number; name: string; description: string | null; avatar: string | null; system_prompt: string | null;
  model: string | null; server_id: number | null; temperature: number | string; kb_id: number | null;
  tools: string[]; api_access: number | string; status: 'active' | 'disabled'; kb_name?: string | null; server_name?: string | null;
}
interface ModelRow { name: string; server_id: number; server_name: string; server_status?: string }
interface Base { id: number; name: string }
interface Form {
  name: string; description: string; avatar: string; system_prompt: string; modelKey: string;
  temperature: number; kb_id: string; tools: string[]; api_access: boolean; status: 'active' | 'disabled';
}

const AVATARS = ['🤖', '🧠', '💬', '🛠️', '📊', '✍️', '🔍', '🎯', '⚡', '🦾'];
const TOOLS: { value: string; label: string }[] = [
  { value: 'search', label: 'Search' },
  { value: 'calculator', label: 'Calculator' },
  { value: 'web_browse', label: 'Web browse' },
  { value: 'code_run', label: 'Code run' },
  { value: 'knowledge_base', label: 'Knowledge base' },
];
const blank: Form = { name: '', description: '', avatar: '🤖', system_prompt: '', modelKey: '', temperature: 0.7, kb_id: '', tools: [], api_access: false, status: 'active' };
const split = (k: string) => { const [s, ...r] = k.split('::'); return { server_id: Number(s), model: r.join('::') }; };

export default function Agents() {
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const { can } = useAuth();
  const canManage = can('agents.manage');

  const [q, setQ] = useState('');
  const [status, setStatus] = useState<'all' | 'active' | 'disabled'>('all');
  const [editing, setEditing] = useState<Agent | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<Form>(blank);
  const [deleting, setDeleting] = useState<Agent | null>(null);

  const list = useQuery({ queryKey: ['agents'], queryFn: () => get<Agent[]>('/agents') });
  const models = useQuery({ queryKey: ['models'], queryFn: () => get<ModelRow[]>('/models'), enabled: canManage && can('models.view') });
  const bases = useQuery({ queryKey: ['knowledge'], queryFn: () => get<Base[]>('/knowledge'), enabled: canManage && can('knowledge.view') });
  const refresh = () => qc.invalidateQueries({ queryKey: ['agents'] });

  const save = useMutation({
    mutationFn: () => {
      const { server_id, model } = form.modelKey ? split(form.modelKey) : { server_id: null, model: null };
      const body = {
        name: form.name.trim(), description: form.description.trim(), avatar: form.avatar, system_prompt: form.system_prompt,
        model, server_id, temperature: form.temperature, kb_id: form.kb_id ? Number(form.kb_id) : null,
        tools: form.tools, api_access: form.api_access ? 1 : 0, status: form.status,
      };
      return editing ? put<Agent>(`/agents/${editing.id}`, body) : post<Agent>('/agents', body);
    },
    onSuccess: () => { toast.success(editing ? 'Agent updated' : 'Agent created'); setFormOpen(false); void refresh(); },
    onError: (e) => toast.error(e),
  });
  const toggle = useMutation({
    mutationFn: (a: Agent) => put<Agent>(`/agents/${a.id}`, { status: a.status === 'active' ? 'disabled' : 'active' }),
    onSuccess: (a) => { toast.success(a?.status === 'active' ? 'Agent enabled' : 'Agent disabled'); void refresh(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/agents/${id}`),
    onSuccess: () => { toast.success('Agent deleted'); setDeleting(null); void refresh(); },
    onError: (e) => toast.error(e),
  });
  const fieldErr = save.error instanceof ApiError ? save.error.fields : {};

  const rows = useMemo(() => {
    const t = q.trim().toLowerCase();
    return (list.data ?? []).filter((a) =>
      (status === 'all' || a.status === status) &&
      (!t || [a.name, a.description ?? '', a.model ?? ''].some((s) => s.toLowerCase().includes(t))));
  }, [list.data, q, status]);

  const modelOptions = useMemo(() => {
    const opts = (models.data ?? []).map((m) => ({ value: `${m.server_id}::${m.name}`, label: `${m.name} · ${m.server_name}` }));
    if (form.modelKey && !opts.some((o) => o.value === form.modelKey)) opts.unshift({ value: form.modelKey, label: split(form.modelKey).model });
    return opts;
  }, [models.data, form.modelKey]);
  const kbOptions = useMemo(() => {
    const opts = (bases.data ?? []).map((b) => ({ value: String(b.id), label: b.name }));
    if (editing?.kb_id && !opts.some((o) => o.value === String(editing.kb_id))) opts.push({ value: String(editing.kb_id), label: editing.kb_name ?? `#${editing.kb_id}` });
    return opts;
  }, [bases.data, editing]);

  const openNew = () => { setEditing(null); setForm(blank); save.reset(); setFormOpen(true); };
  const openEdit = (a: Agent) => {
    setEditing(a);
    setForm({
      name: a.name, description: a.description ?? '', avatar: a.avatar || '🤖', system_prompt: a.system_prompt ?? '',
      modelKey: a.model && a.server_id ? `${a.server_id}::${a.model}` : '', temperature: Number(a.temperature ?? 0.7),
      kb_id: a.kb_id ? String(a.kb_id) : '', tools: a.tools ?? [], api_access: Number(a.api_access) === 1, status: a.status,
    });
    save.reset();
    setFormOpen(true);
  };

  return (
    <>
      <PageHeader
        title="AI agents"
        description="Named assistants with their own model, system prompt, temperature, knowledge base and tools."
        actions={canManage && <Button variant="primary" icon={PlusIcon} onClick={openNew}>New agent</Button>}
      />

      <Card>
        <Toolbar right={<Segmented label="Status" value={status} onChange={setStatus} options={[{ value: 'all', label: 'All' }, { value: 'active', label: 'Active' }, { value: 'disabled', label: 'Disabled' }]} />}>
          <SearchInput value={q} onChange={setQ} placeholder="Search agents…" />
        </Toolbar>
        <div className="p-4 sm:p-6">
          {list.error ? (
            <ErrorState error={list.error} onRetry={() => void list.refetch()} />
          ) : list.isLoading ? (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-44 w-full" />)}</div>
          ) : !rows.length ? (
            <EmptyState
              icon={CpuChipIcon}
              title={list.data?.length ? 'No agents match' : 'No agents yet'}
              description={list.data?.length ? 'Try another search or status.' : 'Create an assistant with its own model, instructions and knowledge.'}
              action={!list.data?.length && canManage ? <Button variant="primary" icon={PlusIcon} onClick={openNew}>Create your first agent</Button> : undefined}
            />
          ) : (
            <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {rows.map((a) => (
                <li key={a.id} className={cx('flex flex-col rounded-lg p-4 ring-1 ring-slate-200 dark:ring-white/10', a.status !== 'active' && 'opacity-70')}>
                  <div className="flex items-start gap-3">
                    <span aria-hidden className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-2xl dark:bg-white/10">{a.avatar || '🤖'}</span>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <h3 className="truncate text-sm font-semibold text-slate-900 dark:text-white">{a.name}</h3>
                        <Badge tone={a.status === 'active' ? 'green' : 'gray'} dot>{a.status}</Badge>
                      </div>
                      <p className="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{a.description || 'No description'}</p>
                    </div>
                  </div>
                  <div className="mt-3 flex flex-wrap gap-1">
                    {a.model ? <Badge tone="blue">{a.model}</Badge> : <Badge>No model</Badge>}
                    {a.server_name && <Badge>{a.server_name}</Badge>}
                    <Badge>temp {Number(a.temperature).toFixed(2)}</Badge>
                    {a.kb_name && <Badge tone="purple">KB: {a.kb_name}</Badge>}
                    {Number(a.api_access) === 1 && <Badge tone="amber">API</Badge>}
                    {a.tools.map((t) => <Badge key={t} tone="indigo">{TOOLS.find((x) => x.value === t)?.label ?? t}</Badge>)}
                  </div>
                  {a.system_prompt && <p className="mt-2 line-clamp-2 rounded-md bg-slate-50 p-2 font-mono text-[11px] text-slate-600 dark:bg-white/5 dark:text-slate-300">{a.system_prompt}</p>}
                  <div className="mt-auto flex items-center gap-2 border-t border-slate-100 pt-3 dark:border-white/5">
                    {can('chat.use') && (
                      <Button size="sm" variant="soft" icon={ChatBubbleLeftRightIcon} disabled={a.status !== 'active'} onClick={() => navigate('/chat', { state: { agent: a } })}>Chat</Button>
                    )}
                    {canManage && (
                      <div className="ml-auto flex items-center gap-1">
                        <Toggle checked={a.status === 'active'} disabled={toggle.isPending} onChange={() => toggle.mutate(a)} />
                        <IconButton label="Edit" icon={PencilSquareIcon} onClick={() => openEdit(a)} />
                        <IconButton label="Delete" icon={TrashIcon} className="hover:text-red-600 dark:hover:text-red-400" onClick={() => setDeleting(a)} />
                      </div>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>

      <FormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={editing ? 'Edit agent' : 'New agent'}
        submitLabel={editing ? 'Save' : 'Create'}
        submitting={save.isPending}
        size="lg"
        onSubmit={() => { if (!form.name.trim()) { toast.error('A name is required.'); return; } save.mutate(); }}
      >
        <div>
          <p className="text-sm/6 font-medium text-slate-900 dark:text-white">Avatar</p>
          <div role="radiogroup" aria-label="Avatar" className="mt-1.5 flex flex-wrap gap-1.5">
            {AVATARS.map((e) => (
              <button
                key={e}
                type="button"
                role="radio"
                aria-checked={form.avatar === e}
                onClick={() => setForm({ ...form, avatar: e })}
                className={cx('flex size-10 items-center justify-center rounded-lg text-xl ring-1', form.avatar === e ? 'bg-brand-50 ring-2 ring-brand-600 dark:bg-blue-500/15 dark:ring-blue-400' : 'ring-slate-200 hover:bg-slate-50 dark:ring-white/10 dark:hover:bg-white/5')}
              >
                {e}
              </button>
            ))}
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="Name" required maxLength={150} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldErr.name} />
          <Input label="Description" maxLength={255} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </div>
        <Textarea label="System prompt" rows={5} value={form.system_prompt} onChange={(e) => setForm({ ...form, system_prompt: e.target.value })} placeholder="You are a friendly support assistant for…" />
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Model" value={form.modelKey} onChange={(e) => setForm({ ...form, modelKey: e.target.value })} placeholder="No default model" options={modelOptions} />
          <Select label="Knowledge base" value={form.kb_id} onChange={(e) => setForm({ ...form, kb_id: e.target.value })} placeholder="None" options={kbOptions} />
        </div>
        <div>
          <label htmlFor="agent-temp" className="flex justify-between text-sm/6 font-medium text-slate-900 dark:text-white">
            Temperature <span className="tabular-nums text-slate-500 dark:text-slate-400">{form.temperature.toFixed(2)}</span>
          </label>
          <input id="agent-temp" type="range" min={0} max={2} step={0.05} value={form.temperature} onChange={(e) => setForm({ ...form, temperature: Number(e.target.value) })} className="mt-2 w-full accent-blue-600" />
          <div className="flex justify-between text-xs text-slate-500 dark:text-slate-400"><span>Precise</span><span>Creative</span></div>
        </div>
        <div>
          <p className="text-sm/6 font-medium text-slate-900 dark:text-white">Tools</p>
          <div className="mt-1.5 grid grid-cols-2 gap-2 sm:grid-cols-3">
            {TOOLS.map((t) => (
              <Checkbox
                key={t.value}
                label={t.label}
                checked={form.tools.includes(t.value)}
                onChange={(on) => setForm({ ...form, tools: on ? [...form.tools, t.value] : form.tools.filter((x) => x !== t.value) })}
              />
            ))}
          </div>
        </div>
        <Toggle label="Gateway API access" description="Lets API keys call this agent through the /api/v1 gateway." checked={form.api_access} onChange={(v) => setForm({ ...form, api_access: v })} />
        <Toggle label="Active" description="Disabled agents cannot be used." checked={form.status === 'active'} onChange={(v) => setForm({ ...form, status: v ? 'active' : 'disabled' })} />
      </FormModal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        busy={remove.isPending}
        title="Delete agent?"
        message={<>“{deleting?.name}” will be removed. Anything calling it through the API stops working.</>}
      />
    </>
  );
}
