import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChatBubbleLeftRightIcon, CheckIcon, ClipboardDocumentIcon, DocumentTextIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  Badge, Button, Card, ConfirmDialog, EmptyState, ErrorState, FormModal, IconButton, Input, PageHeader,
  SearchInput, Select, Skeleton, Textarea, Toggle, Toolbar, useToast,
} from '../components/ui';
import { ApiError, del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago } from '../lib/format';

interface Prompt {
  id: number; user_id: number | null; name: string; category: string; description: string | null; content: string;
  variables: string[]; is_public: number | string; updated_at: string | null;
}
interface Form { name: string; category: string; description: string; content: string; is_public: boolean }

const CATEGORIES = ['General', 'Coding', 'Marketing', 'Support', 'Writing', 'Translation', 'Summarization', 'Documentation'];
const VAR_RE = /\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g;
const varsOf = (s: string) => Array.from(new Set([...s.matchAll(VAR_RE)].map((m) => m[1])));
const fill = (s: string, vals: Record<string, string>) => s.replace(VAR_RE, (all, v: string) => (vals[v]?.trim() ? vals[v] : all));
const blank: Form = { name: '', category: 'General', description: '', content: '', is_public: true };

export default function Prompts() {
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const { can } = useAuth();
  const canManage = can('prompts.manage');
  const canChat = can('chat.use');

  const [q, setQ] = useState('');
  const [cat, setCat] = useState('');
  const [editing, setEditing] = useState<Prompt | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<Form>(blank);
  const [deleting, setDeleting] = useState<Prompt | null>(null);
  const [copied, setCopied] = useState<number | null>(null);
  const [using, setUsing] = useState<{ p: Prompt; mode: 'copy' | 'chat' } | null>(null);
  const [vals, setVals] = useState<Record<string, string>>({});

  const list = useQuery({ queryKey: ['prompts'], queryFn: () => get<Prompt[]>('/prompts') });
  const refresh = () => qc.invalidateQueries({ queryKey: ['prompts'] });

  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, is_public: form.is_public ? 1 : 0 };
      return editing ? put<Prompt>(`/prompts/${editing.id}`, body) : post<Prompt>('/prompts', body);
    },
    onSuccess: () => { toast.success(editing ? 'Prompt updated' : 'Prompt saved'); setFormOpen(false); void refresh(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/prompts/${id}`),
    onSuccess: () => { toast.success('Prompt deleted'); setDeleting(null); void refresh(); },
    onError: (e) => toast.error(e),
  });
  const fieldErr = save.error instanceof ApiError ? save.error.fields : {};

  const categories = useMemo(() => Array.from(new Set([...CATEGORIES, ...(list.data ?? []).map((p) => p.category)])).filter(Boolean), [list.data]);
  const rows = useMemo(() => {
    const term = q.trim().toLowerCase();
    return (list.data ?? []).filter((p) =>
      (!cat || p.category === cat) &&
      (!term || [p.name, p.description ?? '', p.content, p.category].some((s) => s.toLowerCase().includes(term))));
  }, [list.data, q, cat]);

  const openNew = () => { setEditing(null); setForm(blank); save.reset(); setFormOpen(true); };
  const openEdit = (p: Prompt) => {
    setEditing(p);
    setForm({ name: p.name, category: p.category || 'General', description: p.description ?? '', content: p.content, is_public: Number(p.is_public) === 1 });
    save.reset();
    setFormOpen(true);
  };

  const copyText = (id: number, text: string) =>
    navigator.clipboard?.writeText(text).then(() => { setCopied(id); setTimeout(() => setCopied(null), 1500); toast.success('Copied to clipboard'); }, () => toast.error('Copy failed'));

  const finish = (p: Prompt, mode: 'copy' | 'chat', text: string) => {
    if (mode === 'chat') navigate('/chat', { state: { prompt: text } });
    else void copyText(p.id, text);
  };
  const start = (p: Prompt, mode: 'copy' | 'chat') => {
    if (p.variables.length) { setVals({}); setUsing({ p, mode }); }
    else finish(p, mode, p.content);
  };

  const formVars = varsOf(form.content);

  return (
    <>
      <PageHeader
        title="Prompt library"
        description="Reusable prompt templates. Use {{variable}} placeholders and fill them in when you copy or use a prompt."
        actions={canManage && <Button variant="primary" icon={PlusIcon} onClick={openNew}>New prompt</Button>}
      />

      <Card>
        <Toolbar
          right={<span className="text-sm text-slate-500 tabular-nums dark:text-slate-400">{rows.length} of {list.data?.length ?? 0}</span>}
        >
          <SearchInput value={q} onChange={setQ} placeholder="Search prompts…" />
          <div className="w-full sm:w-48">
            <Select aria-label="Category" value={cat} onChange={(e) => setCat(e.target.value)} placeholder="All categories" options={categories.map((c) => ({ value: c, label: c }))} />
          </div>
        </Toolbar>

        <div className="p-4 sm:p-6">
          {list.error ? (
            <ErrorState error={list.error} onRetry={() => void list.refetch()} />
          ) : list.isLoading ? (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-48 w-full" />)}</div>
          ) : !rows.length ? (
            <EmptyState
              icon={DocumentTextIcon}
              title={list.data?.length ? 'No prompts match' : 'No prompts yet'}
              description={list.data?.length ? 'Try another search or category.' : 'Save the prompts you use often and reuse them in chat.'}
              action={!list.data?.length && canManage ? <Button variant="primary" icon={PlusIcon} onClick={openNew}>Create your first prompt</Button> : undefined}
            />
          ) : (
            <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {rows.map((p) => (
                <li key={p.id} className="flex flex-col rounded-lg p-4 ring-1 ring-slate-200 dark:ring-white/10">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <h3 className="truncate text-sm font-semibold text-slate-900 dark:text-white">{p.name}</h3>
                      <div className="mt-1 flex flex-wrap gap-1">
                        <Badge tone="blue">{p.category}</Badge>
                        {Number(p.is_public) !== 1 && <Badge>Private</Badge>}
                      </div>
                    </div>
                    <IconButton
                      label={p.variables.length ? 'Fill in and copy' : 'Copy'}
                      icon={copied === p.id ? CheckIcon : ClipboardDocumentIcon}
                      className={copied === p.id ? 'text-emerald-600 dark:text-emerald-400' : undefined}
                      onClick={() => start(p, 'copy')}
                    />
                  </div>
                  {p.description && <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{p.description}</p>}
                  <pre className="mt-2 max-h-28 flex-1 overflow-hidden rounded-md bg-slate-50 p-2 font-mono text-xs/5 whitespace-pre-wrap text-slate-600 dark:bg-white/5 dark:text-slate-300">{p.content}</pre>
                  {p.variables.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1">{p.variables.map((v) => <Badge key={v} tone="purple">{`{{${v}}}`}</Badge>)}</div>
                  )}
                  <div className="mt-3 flex items-center gap-2 border-t border-slate-100 pt-3 dark:border-white/5">
                    {canChat && <Button size="sm" variant="soft" icon={ChatBubbleLeftRightIcon} onClick={() => start(p, 'chat')}>Use in chat</Button>}
                    {p.updated_at && <span className="ml-auto text-[11px] text-slate-500 dark:text-slate-400">{ago(p.updated_at)}</span>}
                    {canManage && (
                      <div className={p.updated_at ? 'flex' : 'ml-auto flex'}>
                        <IconButton label="Edit" icon={PencilSquareIcon} onClick={() => openEdit(p)} />
                        <IconButton label="Delete" icon={TrashIcon} className="hover:text-red-600 dark:hover:text-red-400" onClick={() => setDeleting(p)} />
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
        title={editing ? 'Edit prompt' : 'New prompt'}
        submitLabel={editing ? 'Save' : 'Create'}
        submitting={save.isPending}
        size="lg"
        onSubmit={() => {
          if (!form.name.trim() || !form.content.trim()) { toast.error('Name and content are required.'); return; }
          save.mutate();
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="Name" required maxLength={150} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldErr.name} />
          <div>
            <Input label="Category" list="prompt-categories" maxLength={64} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} error={fieldErr.category} />
            <datalist id="prompt-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
          </div>
        </div>
        <Input label="Description" maxLength={255} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="What this prompt is for" />
        <Textarea
          label="Prompt"
          required
          rows={9}
          className="font-mono"
          value={form.content}
          onChange={(e) => setForm({ ...form, content: e.target.value })}
          placeholder={"Summarise the following text for {{audience}}:\n\n{{text}}"}
          error={fieldErr.content}
          hint="Use {{variable}} for placeholders. Variables are detected automatically."
        />
        {formVars.length > 0 && (
          <div className="flex flex-wrap items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
            Variables: {formVars.map((v) => <Badge key={v} tone="purple">{`{{${v}}}`}</Badge>)}
          </div>
        )}
        <Toggle label="Shared with everyone" description="Off keeps the prompt visible only to you." checked={form.is_public} onChange={(v) => setForm({ ...form, is_public: v })} />
      </FormModal>

      <FormModal
        open={!!using}
        onClose={() => setUsing(null)}
        title={`Fill in “${using?.p.name ?? ''}”`}
        description="Blank variables stay as {{placeholders}}."
        submitLabel={using?.mode === 'chat' ? 'Open in chat' : 'Copy'}
        size="lg"
        onSubmit={() => { if (using) { finish(using.p, using.mode, fill(using.p.content, vals)); setUsing(null); } }}
      >
        {using?.p.variables.map((v) => (
          <Textarea key={v} label={v} rows={2} value={vals[v] ?? ''} onChange={(e) => setVals({ ...vals, [v]: e.target.value })} />
        ))}
        {using && (
          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-900 dark:text-white">Preview</p>
            <pre className="max-h-48 overflow-auto rounded-md bg-slate-50 p-3 font-mono text-xs/5 whitespace-pre-wrap text-slate-700 dark:bg-white/5 dark:text-slate-300">{fill(using.p.content, vals)}</pre>
          </div>
        )}
      </FormModal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        busy={remove.isPending}
        title="Delete prompt?"
        message={<>“{deleting?.name}” will be removed from the library for everyone.</>}
      />
    </>
  );
}
