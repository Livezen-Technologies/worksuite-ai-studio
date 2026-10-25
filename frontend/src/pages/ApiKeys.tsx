import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowPathIcon, KeyIcon, PencilSquareIcon, PlusIcon, TrashIcon, ClipboardDocumentIcon, CheckIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, CardHeader, Checkbox, CodeBlock, ConfirmDialog, DataTable, EmptyState, FormModal,
  FormSection, IconButton, Input, Meter, Modal, PageHeader, StatusBadge, Textarea, Toggle, useToast, type Column,
} from '../components/ui';
import { ApiError, del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, compact, dateTime, num } from '../lib/format';

interface ApiKey {
  id: number; name: string; api_key: string; secret_prefix?: string | null; scopes: string[];
  allowed_models: string[] | null; allowed_ips: string[] | null; daily_token_limit: number | null; monthly_token_limit: number | null;
  rate_limit_per_min: number | null; status: 'active' | 'disabled'; expires_at?: string | null; last_used_at?: string | null;
  created_at?: string | null; tokens_30d?: number; secret?: string;
}

const SCOPES = [
  { value: 'chat', label: 'Chat', hint: 'Also covers translate and summarize' },
  { value: 'generate', label: 'Generate' },
  { value: 'embeddings', label: 'Embeddings' },
  { value: 'models', label: 'List models' },
  { value: 'download_models', label: 'Download models', hint: 'Engine packages: Whisper, vision and Sinhala chat model files' },
  { value: 'download_datasets', label: 'Download datasets', hint: 'Rights-approved datasets as JSONL; never a locked test split' },
];

const gatewayBase = () => `${window.location.origin}/api/v1`;
const downloadExample = (key = 'ohub_your_key', secret = 'sk_your_secret') =>
  `# what this key may download (packages with file URLs + sha256, approved datasets)
curl -H "X-Api-Key: ${key}" -H "X-Api-Secret: ${secret}" ${gatewayBase()}/catalog

# a model file (resumable)
curl -C - -o model.bin -H "X-Api-Key: ${key}" -H "X-Api-Secret: ${secret}" \\
  "${gatewayBase()}/packages/PACKAGE_ID/files?path=model.bin"

# a dataset as JSONL (frozen sets: train and dev only)
curl -o data.jsonl -H "X-Api-Key: ${key}" -H "X-Api-Secret: ${secret}" \\
  "${gatewayBase()}/datasets/36/download?split=train"`;
const curlFor = (key = 'ohub_your_key', secret = 'sk_your_secret') =>
  `curl ${gatewayBase()}/chat \\
  -H "X-Api-Key: ${key}" \\
  -H "X-Api-Secret: ${secret}" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"llama3","messages":[{"role":"user","content":"Hello"}]}'`;

function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
  const [done, setDone] = useState(false);
  return (
    <IconButton
      label={done ? 'Copied' : label}
      icon={done ? CheckIcon : ClipboardDocumentIcon}
      variant="ghost"
      size="sm"
      onClick={() => { void navigator.clipboard?.writeText(text); setDone(true); setTimeout(() => setDone(false), 1500); }}
    />
  );
}

interface FormState {
  name: string; scopes: string[]; rate_limit_per_min: string; daily_token_limit: string; monthly_token_limit: string;
  allowed_models: string[]; allowed_ips: string; status: 'active' | 'disabled'; expires_at: string;
}
const blank: FormState = { name: '', scopes: ['chat', 'generate', 'embeddings', 'models'], rate_limit_per_min: '60', daily_token_limit: '', monthly_token_limit: '', allowed_models: [], allowed_ips: '', status: 'active', expires_at: '' };
const toForm = (k: ApiKey): FormState => ({
  name: k.name, scopes: k.scopes ?? [], rate_limit_per_min: k.rate_limit_per_min?.toString() ?? '', daily_token_limit: k.daily_token_limit?.toString() ?? '',
  monthly_token_limit: k.monthly_token_limit?.toString() ?? '', allowed_models: k.allowed_models ?? [], allowed_ips: (k.allowed_ips ?? []).join('\n'),
  status: k.status, expires_at: k.expires_at ? k.expires_at.slice(0, 10) : '',
});

function KeyForm({ open, onClose, editing, onCreated }: { open: boolean; onClose: () => void; editing: ApiKey | null; onCreated: (k: ApiKey) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [f, setF] = useState<FormState>(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [lastOpen, setLastOpen] = useState(false);
  if (open !== lastOpen) { setLastOpen(open); if (open) { setF(editing ? toForm(editing) : blank); setErrors({}); } }

  const models = useQuery({ queryKey: ['models'], queryFn: () => get<{ name: string }[]>('/models'), enabled: open, retry: false });
  const modelNames = useMemo(() => Array.from(new Set((models.data ?? []).map((m) => m.name))).sort(), [models.data]);
  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setF((s) => ({ ...s, [k]: v }));

  const save = useMutation({
    mutationFn: () => {
      const body = {
        name: f.name.trim(), scopes: f.scopes, rate_limit_per_min: f.rate_limit_per_min, daily_token_limit: f.daily_token_limit,
        monthly_token_limit: f.monthly_token_limit, allowed_models: f.allowed_models,
        allowed_ips: f.allowed_ips.split(/[\s,]+/).map((s) => s.trim()).filter(Boolean),
        expires_at: f.expires_at ? `${f.expires_at} 23:59:59` : '',
        ...(editing ? { status: f.status } : {}),
      };
      return editing ? put<ApiKey>(`/apikeys/${editing.id}`, body) : post<ApiKey>('/apikeys', body);
    },
    onSuccess: (k) => {
      qc.invalidateQueries({ queryKey: ['apikeys'] });
      if (editing) toast.success('API key updated');
      else onCreated(k);
      onClose();
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });

  const toggleIn = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

  return (
    <FormModal open={open} onClose={onClose} size="lg" title={editing ? `Edit ${editing.name}` : 'Create API key'}
      description={editing ? undefined : 'The secret is shown once, right after the key is created.'}
      submitLabel={editing ? 'Save changes' : 'Create key'} submitting={save.isPending}
      onSubmit={() => { if (f.name.trim().length < 2) { setErrors({ name: 'A key name of at least 2 characters is required.' }); return; } save.mutate(); }}>
      <div className="space-y-6">
        <Input label="Name" required placeholder="e.g. Mobile app" value={f.name} onChange={(e) => set('name', e.target.value)} error={errors.name} autoFocus />
        <FormSection title="Scopes" description="Which gateway endpoints this key may call.">
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {SCOPES.map((s) => (
              <Checkbox key={s.value} checked={f.scopes.includes(s.value)} onChange={() => set('scopes', toggleIn(f.scopes, s.value))}
                label={<span>{s.label}{s.hint && <span className="block text-xs text-slate-500 dark:text-slate-400">{s.hint}</span>}</span>} />
            ))}
          </div>
        </FormSection>
        <FormSection title="Limits" description="Leave a quota empty for no limit.">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Input label="Requests / min" type="number" min={0} value={f.rate_limit_per_min} onChange={(e) => set('rate_limit_per_min', e.target.value)} error={errors.rate_limit_per_min} />
            <Input label="Daily tokens" type="number" min={0} placeholder="Unlimited" value={f.daily_token_limit} onChange={(e) => set('daily_token_limit', e.target.value)} error={errors.daily_token_limit} />
            <Input label="Monthly tokens" type="number" min={0} placeholder="Unlimited" value={f.monthly_token_limit} onChange={(e) => set('monthly_token_limit', e.target.value)} error={errors.monthly_token_limit} />
          </div>
        </FormSection>
        <FormSection title="Restrictions" description="Nothing selected means every model and every IP address is allowed.">
          <div className="space-y-4">
            <div>
              <div className="mb-1.5 text-sm font-medium text-slate-900 dark:text-white">Allowed models</div>
              {modelNames.length ? (
                <div className="grid max-h-44 grid-cols-1 gap-2 overflow-y-auto rounded-lg p-3 ring-1 ring-slate-200 sm:grid-cols-2 dark:ring-white/10">
                  {modelNames.map((m) => <Checkbox key={m} checked={f.allowed_models.includes(m)} onChange={() => set('allowed_models', toggleIn(f.allowed_models, m))} label={<span className="font-mono text-xs">{m}</span>} />)}
                </div>
              ) : (
                <Input placeholder="llama3, mistral (comma separated)" value={f.allowed_models.join(', ')}
                  onChange={(e) => set('allowed_models', e.target.value.split(',').map((s) => s.trim()).filter(Boolean))}
                  hint={models.isLoading ? 'Loading models…' : 'The model list could not be loaded; type names instead.'} />
              )}
            </div>
            <Textarea label="Allowed IPs" rows={3} placeholder={'203.0.113.10\n198.51.100.0/24'} value={f.allowed_ips} onChange={(e) => set('allowed_ips', e.target.value)} hint="One per line or comma separated." error={errors.allowed_ips} />
            <Input label="Expires on" type="date" value={f.expires_at} onChange={(e) => set('expires_at', e.target.value)} hint="Optional." />
            {editing && <Toggle checked={f.status === 'active'} onChange={(v) => set('status', v ? 'active' : 'disabled')} label="Active" description="A disabled key is refused by the gateway." />}
          </div>
        </FormSection>
      </div>
    </FormModal>
  );
}

function SecretModal({ k, onClose }: { k: ApiKey | null; onClose: () => void }) {
  const [ack, setAck] = useState(false);
  const [last, setLast] = useState<ApiKey | null>(null);
  if (k !== last) { setLast(k); setAck(false); }
  return (
    <Modal open={!!k} onClose={() => ack && onClose()} size="lg" title="Copy your API secret"
      footer={<Button disabled={!ack} onClick={onClose}>Done</Button>}>
      {k && (
        <div className="space-y-4">
          <Alert tone="warning" title="This secret is shown only once">Store it somewhere safe now. You can rotate it later, but it can never be viewed again.</Alert>
          {[{ l: 'Key (X-Api-Key)', v: k.api_key }, { l: 'Secret (X-Api-Secret)', v: k.secret ?? '' }].map((x) => (
            <div key={x.l}>
              <div className="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">{x.l}</div>
              <div className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200 dark:bg-white/5 dark:ring-white/10">
                <code className="min-w-0 flex-1 break-all font-mono text-sm text-slate-900 dark:text-slate-100">{x.v}</code>
                <CopyButton text={x.v} />
              </div>
            </div>
          ))}
          <div>
            <div className="mb-1 flex items-center justify-between text-xs font-medium text-slate-500 dark:text-slate-400">Try it <CopyButton text={curlFor(k.api_key, k.secret)} /></div>
            <CodeBlock>{curlFor(k.api_key, k.secret)}</CodeBlock>
          </div>
          <Checkbox checked={ack} onChange={setAck} label="I have copied the secret" />
        </div>
      )}
    </Modal>
  );
}

function Quota({ used, limit }: { used: number; limit: number | null }) {
  if (!limit) return <span className="text-slate-500 dark:text-slate-400">Unlimited</span>;
  return <div className="w-28"><Meter value={Math.min(used, limit)} max={limit} label={`${compact(used)} / ${compact(limit)}`} /></div>;
}

export default function ApiKeys() {
  const { can } = useAuth();
  const manage = can('apikeys.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<ApiKey | null>(null);
  const [revealed, setRevealed] = useState<ApiKey | null>(null);
  const [confirm, setConfirm] = useState<{ kind: 'rotate' | 'delete'; key: ApiKey } | null>(null);

  const q = useQuery({ queryKey: ['apikeys'], queryFn: () => get<ApiKey[]>('/apikeys') });
  const rotate = useMutation({
    mutationFn: (id: number) => post<ApiKey>(`/apikeys/${id}/rotate`),
    onSuccess: (k) => { qc.invalidateQueries({ queryKey: ['apikeys'] }); setConfirm(null); setRevealed(k); toast.success('Secret rotated', 'The old secret stops working immediately.'); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/apikeys/${id}`),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['apikeys'] }); setConfirm(null); toast.success('API key deleted'); },
    onError: (e) => toast.error(e),
  });

  const openCreate = () => { setEditing(null); setFormOpen(true); };
  const rows = q.data ?? [];
  const columns: Column<ApiKey>[] = [
    { key: 'name', header: 'Key', cell: (k) => (
      <div className="min-w-0">
        <div className="font-medium text-slate-900 dark:text-white">{k.name}</div>
        <div className="flex items-center gap-1 font-mono text-xs text-slate-500 dark:text-slate-400">{k.api_key}<CopyButton text={k.api_key} label="Copy key" /></div>
      </div>
    ) },
    { key: 'scopes', header: 'Scopes', hideBelow: 'lg', cell: (k) => <div className="flex flex-wrap gap-1">{(k.scopes ?? []).map((s) => <Badge key={s} tone="blue">{s}</Badge>)}</div> },
    { key: 'restrict', header: 'Restrictions', hideBelow: 'lg', cell: (k) => (
      <div className="space-y-0.5 text-xs text-slate-600 dark:text-slate-300">
        <div>{k.allowed_models?.length ? `${k.allowed_models.length} model${k.allowed_models.length > 1 ? 's' : ''}` : 'All models'}</div>
        <div>{k.allowed_ips?.length ? `${k.allowed_ips.length} IP rule${k.allowed_ips.length > 1 ? 's' : ''}` : 'Any IP'}</div>
      </div>
    ) },
    { key: 'rate', header: 'Rate', hideBelow: 'md', cell: (k) => <span className="text-sm">{k.rate_limit_per_min ? `${num(k.rate_limit_per_min)}/min` : '—'}</span> },
    { key: 'usage', header: 'Tokens (30d)', hideBelow: 'sm', cell: (k) => (
      <div className="space-y-1 text-xs">
        <div className="font-medium text-slate-900 dark:text-white">{compact(k.tokens_30d ?? 0)}</div>
        {k.monthly_token_limit ? <Quota used={k.tokens_30d ?? 0} limit={k.monthly_token_limit} /> : null}
        {k.daily_token_limit ? <div className="text-slate-500 dark:text-slate-400">Daily cap {compact(k.daily_token_limit)}</div> : null}
      </div>
    ) },
    { key: 'status', header: 'Status', cell: (k) => (
      <div className="space-y-0.5">
        <StatusBadge status={k.status} />
        {k.expires_at && <div className="text-xs text-slate-500 dark:text-slate-400">Expires {dateTime(k.expires_at)}</div>}
      </div>
    ) },
    { key: 'last', header: 'Last used', hideBelow: 'md', cell: (k) => <span className="text-sm text-slate-500 dark:text-slate-400" title={dateTime(k.last_used_at)}>{k.last_used_at ? ago(k.last_used_at) : 'Never'}</span> },
    ...(manage ? [{ key: 'actions', header: '', align: 'right' as const, cell: (k: ApiKey) => (
      <ActionMenu actions={[
        { label: 'Edit', icon: PencilSquareIcon, onClick: () => { setEditing(k); setFormOpen(true); } },
        { label: 'Rotate secret', icon: ArrowPathIcon, onClick: () => setConfirm({ kind: 'rotate', key: k }) },
        { label: 'Delete', icon: TrashIcon, danger: true, onClick: () => setConfirm({ kind: 'delete', key: k }) },
      ]} />
    ) }] : []),
  ];

  return (
    <>
      <PageHeader title="API keys" description="Credentials for the external /api/v1 gateway, with scopes, quotas and IP rules."
        actions={manage && <Button icon={PlusIcon} onClick={openCreate}>Create key</Button>} />
      <div className="space-y-6">
        <Card>
          <DataTable columns={columns} rows={rows} rowKey={(k) => k.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()}
            empty={<EmptyState icon={KeyIcon} title="No API keys yet" description="Create a key to let other apps call your models through the gateway."
              action={manage && <Button icon={PlusIcon} onClick={openCreate}>Create key</Button>} />} />
        </Card>
        <Card>
          <CardHeader title="Using the gateway" description="Send the key and secret as headers, or as a Bearer token of the form key:secret." />
          <div className="space-y-4 p-4 sm:p-6">
            <div>
              <div className="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">Base URL</div>
              <div className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200 dark:bg-white/5 dark:ring-white/10">
                <code className="min-w-0 flex-1 break-all font-mono text-sm text-slate-900 dark:text-slate-100">{gatewayBase()}</code>
                <CopyButton text={gatewayBase()} />
              </div>
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Endpoints: GET /models, POST /chat, /generate, /embeddings, /translate, /summarize. Downloads: GET /catalog, /packages/{'{id}'}/files?path=…, /datasets/{'{id}'}/download?split=train,dev.</p>
            </div>
            <div>
              <div className="mb-1 flex items-center justify-between text-xs font-medium text-slate-500 dark:text-slate-400">Example request <CopyButton text={curlFor()} /></div>
              <CodeBlock>{curlFor()}</CodeBlock>
            </div>
            <div>
              <div className="mb-1 flex items-center justify-between text-xs font-medium text-slate-500 dark:text-slate-400">Download a model or dataset <CopyButton text={downloadExample()} /></div>
              <CodeBlock>{downloadExample()}</CodeBlock>
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Needs the Download models or Download datasets scope. Model files resume with <code>-C -</code>; check each file against the sha256 in the catalog. Datasets carry their permitted use — the Sinhala sets include non-commercial licences.</p>
            </div>
          </div>
        </Card>
      </div>

      <KeyForm open={formOpen} onClose={() => setFormOpen(false)} editing={editing} onCreated={(k) => { toast.success('API key created', 'Copy the secret now.'); setRevealed(k); }} />
      <SecretModal k={revealed} onClose={() => setRevealed(null)} />
      <ConfirmDialog open={confirm?.kind === 'rotate'} onClose={() => setConfirm(null)} tone="primary" confirmLabel="Rotate secret"
        busy={rotate.isPending} onConfirm={() => confirm && rotate.mutate(confirm.key.id)} title="Rotate this secret?"
        message={<span className="flex gap-2"><ExclamationTriangleIcon className="h-5 w-5 shrink-0 text-amber-500 dark:text-amber-400" />Apps using “{confirm?.key.name}” will be refused until they switch to the new secret.</span>} />
      <ConfirmDialog open={confirm?.kind === 'delete'} onClose={() => setConfirm(null)} busy={remove.isPending}
        onConfirm={() => confirm && remove.mutate(confirm.key.id)} title="Delete this API key?"
        message={`“${confirm?.key.name}” stops working at once. Its request logs are kept.`} />
    </>
  );
}
