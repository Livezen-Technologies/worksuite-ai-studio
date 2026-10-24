import { useEffect, useMemo, useState, type ComponentType, type SVGProps } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArchiveBoxIcon, ArrowsRightLeftIcon, BellIcon, BuildingOfficeIcon, CpuChipIcon, EnvelopeIcon,
} from '@heroicons/react/24/outline';
import {
  Alert, Badge, Button, Card, ErrorState, FormSection, Input, PageHeader, Select, Skeleton, Tabs, Toggle, cx, useToast,
} from '../components/ui';
import { ApiError, get, put } from '../lib/api';
import { useAuth } from '../lib/auth';

type Values = Record<string, string>;
type FieldType = 'text' | 'number' | 'bool' | 'select' | 'password' | 'email' | 'url';
interface FieldDef { key: string; label: string; type?: FieldType; options?: { value: string; label: string }[]; hint?: string; placeholder?: string; wide?: boolean }
interface SectionDef { title: string; description?: string; fields: FieldDef[] }
interface GroupDef { key: string; label: string; description: string; icon: ComponentType<SVGProps<SVGSVGElement>>; sections: SectionDef[] }

/** The API masks these keys on read and keeps the stored value when the mask is sent back. */
const MASK = '••••••••';
const opts = (...v: [string, string][]) => v.map(([value, label]) => ({ value, label }));

/* The six groups SettingsController accepts, with every field the old screen edited. */
const GROUPS: GroupDef[] = [
  {
    key: 'general', label: 'General', icon: BuildingOfficeIcon, description: 'Name, organisation and locale.',
    sections: [
      { title: 'Identity', description: 'How this installation names itself.', fields: [
        { key: 'site_name', label: 'Site name', placeholder: 'OllamaHub' },
        { key: 'company_name', label: 'Company name' },
      ] },
      { title: 'Locale', description: 'Defaults for dates and the interface language.', fields: [
        { key: 'timezone', label: 'Timezone', placeholder: 'UTC', hint: 'An IANA name such as Asia/Colombo.' },
        { key: 'language', label: 'Language', type: 'select', options: opts(['en', 'English'], ['si', 'Sinhala'], ['ta', 'Tamil'], ['fr', 'French'], ['de', 'German'], ['es', 'Spanish']) },
      ] },
    ],
  },
  {
    key: 'ai', label: 'AI', icon: CpuChipIcon, description: 'Defaults for model requests.',
    sections: [
      { title: 'Model defaults', description: 'Used when a request does not name a model.', fields: [
        { key: 'default_model', label: 'Default model', placeholder: 'e.g. llama3.1:8b', wide: true },
        { key: 'timeout', label: 'Timeout (seconds)', type: 'number' },
        { key: 'retry_count', label: 'Retry count', type: 'number' },
      ] },
      { title: 'Behaviour', fields: [
        { key: 'streaming', label: 'Streaming', type: 'bool', hint: 'Send tokens as they are generated.' },
        { key: 'cache', label: 'Cache responses', type: 'bool', hint: 'Reuse answers to identical requests.' },
        { key: 'compression', label: 'Compression', type: 'bool', hint: 'Compress responses on the wire.' },
      ] },
    ],
  },
  {
    key: 'smtp', label: 'Email (SMTP)', icon: EnvelopeIcon, description: 'Outgoing mail server.',
    sections: [
      { title: 'Server', description: 'Where outgoing mail is sent.', fields: [
        { key: 'host', label: 'Host', placeholder: 'smtp.example.com' },
        { key: 'port', label: 'Port', type: 'number' },
        { key: 'encryption', label: 'Encryption', type: 'select', options: opts(['tls', 'TLS'], ['ssl', 'SSL'], ['none', 'None']) },
      ] },
      { title: 'Authentication', fields: [
        { key: 'username', label: 'Username' },
        { key: 'password', label: 'Password', type: 'password' },
      ] },
      { title: 'Sender', description: 'Shown as the sender of every email.', fields: [
        { key: 'from_email', label: 'From email', type: 'email' },
        { key: 'from_name', label: 'From name' },
      ] },
    ],
  },
  {
    key: 'storage', label: 'Storage', icon: ArchiveBoxIcon, description: 'Where files are kept.',
    sections: [
      { title: 'Driver', fields: [
        { key: 'driver', label: 'Driver', type: 'select', options: opts(['local', 'Local disk'], ['s3', 'Amazon S3'], ['minio', 'MinIO / S3-compatible']) },
      ] },
      { title: 'Object storage', description: 'Only used by the S3 and MinIO drivers.', fields: [
        { key: 's3_bucket', label: 'Bucket' },
        { key: 's3_region', label: 'Region', placeholder: 'us-east-1' },
        { key: 's3_key', label: 'Access key' },
        { key: 's3_secret', label: 'Secret key', type: 'password' },
        { key: 's3_endpoint', label: 'Endpoint (MinIO)', type: 'url', placeholder: 'https://minio.example.com', wide: true },
      ] },
    ],
  },
  {
    key: 'notifications', label: 'Notifications', icon: BellIcon, description: 'Channels alerts are sent to.',
    sections: [
      { title: 'Email', fields: [
        { key: 'email_enabled', label: 'Email notifications', type: 'bool', hint: 'Uses the SMTP settings.' },
      ] },
      { title: 'Webhooks', description: 'Leave a field blank to turn that channel off.', fields: [
        { key: 'slack_webhook', label: 'Slack webhook URL', type: 'url', wide: true },
        { key: 'discord_webhook', label: 'Discord webhook URL', type: 'url', wide: true },
        { key: 'webhook_url', label: 'Generic webhook URL', type: 'url', wide: true },
      ] },
      { title: 'Telegram', fields: [
        { key: 'telegram_bot_token', label: 'Bot token', type: 'password' },
        { key: 'telegram_chat_id', label: 'Chat ID' },
      ] },
    ],
  },
  {
    key: 'gateway', label: 'Load balancing', icon: ArrowsRightLeftIcon, description: 'How the /api/v1 gateway picks a node.',
    sections: [
      { title: 'Routing', fields: [
        { key: 'strategy', label: 'Routing strategy', type: 'select', options: opts(['round_robin', 'Round robin'], ['random', 'Random'], ['least_busy', 'Least busy']) },
        { key: 'sticky_sessions', label: 'Sticky sessions', type: 'bool', hint: 'Pin an API key to one node where possible.' },
        { key: 'include_degraded', label: 'Route to degraded nodes too', type: 'bool', hint: 'Otherwise only online nodes receive traffic.' },
      ] },
      { title: 'Failover & health', fields: [
        { key: 'failover', label: 'Fail over to the next node on error', type: 'bool' },
        { key: 'max_retries', label: 'Max retries per request', type: 'number' },
        { key: 'health_interval', label: 'Health-check interval (seconds)', type: 'number' },
      ] },
    ],
  },
];

const SECRET_KEYS = new Set(GROUPS.flatMap((g) => g.sections.flatMap((s) => s.fields.filter((f) => f.type === 'password').map((f) => f.key))));

export default function Settings() {
  const [active, setActive] = useState(GROUPS[0].key);
  const group = GROUPS.find((g) => g.key === active) ?? GROUPS[0];

  return (
    <>
      <PageHeader title="Settings" description="Platform configuration, grouped by area." />
      <div className="lg:hidden">
        <Tabs tabs={GROUPS.map((g) => ({ key: g.key, label: g.label }))} value={active} onChange={setActive} className="mb-4" />
      </div>
      <div className="lg:grid lg:grid-cols-[14rem_1fr] lg:gap-8">
        <nav aria-label="Settings sections" className="hidden lg:block">
          <ul className="space-y-1">
            {GROUPS.map((g) => (
              <li key={g.key}>
                <button
                  type="button"
                  onClick={() => setActive(g.key)}
                  aria-current={g.key === active ? 'page' : undefined}
                  className={cx(
                    'group flex w-full items-center gap-x-3 rounded-md px-3 py-2 text-left text-sm font-medium',
                    g.key === active
                      ? 'bg-slate-100 text-brand-700 dark:bg-white/10 dark:text-white'
                      : 'text-slate-700 hover:bg-slate-50 hover:text-brand-700 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white',
                  )}
                >
                  <g.icon className={cx('size-5 shrink-0', g.key === active ? 'text-brand-600 dark:text-white' : 'text-slate-400 group-hover:text-brand-600 dark:text-slate-500 dark:group-hover:text-white')} aria-hidden />
                  {g.label}
                </button>
              </li>
            ))}
          </ul>
        </nav>
        <GroupForm key={group.key} group={group} />
      </div>
    </>
  );
}

function GroupForm({ group }: { group: GroupDef }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { can } = useAuth();
  const canManage = can('settings.manage');

  const q = useQuery({ queryKey: ['settings', group.key], queryFn: () => get<Values>(`/settings/${group.key}`) });

  // Secrets are never echoed: a masked secret starts blank and is only sent when typed or cleared.
  const [values, setValues] = useState<Values>({});
  const [cleared, setCleared] = useState<Record<string, boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});

  const initial = useMemo(() => {
    const out: Values = {};
    for (const [k, v] of Object.entries(q.data ?? {})) out[k] = SECRET_KEYS.has(k) ? '' : String(v ?? '');
    return out;
  }, [q.data]);
  const secretSaved = (k: string) => !!q.data?.[k] && q.data[k] !== '';

  useEffect(() => { setValues(initial); setCleared({}); setErrors({}); }, [initial]);

  const changed = Object.keys(values).filter((k) => (values[k] ?? '') !== (initial[k] ?? '') || cleared[k]);
  const dirty = changed.length > 0;

  const save = useMutation({
    mutationFn: () => {
      const body: Values = {};
      for (const k of changed) body[k] = values[k] ?? '';
      return put<Values>(`/settings/${group.key}`, body);
    },
    onSuccess: (data) => {
      qc.setQueryData(['settings', group.key], data);
      toast.success(`${group.label} settings saved`);
    },
    onError: (e) => {
      if (e instanceof ApiError) setErrors(e.fields);
      toast.error(e);
    },
  });

  const set = (k: string, v: string) => setValues((s) => ({ ...s, [k]: v }));
  const discard = () => { setValues(initial); setCleared({}); setErrors({}); };

  if (q.error) return <Card><ErrorState error={q.error} onRetry={() => q.refetch()} /></Card>;

  return (
    <Card padded>
      <div className="mb-6 flex items-start justify-between gap-4 border-b border-slate-200 pb-4 dark:border-white/10">
        <div>
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{group.label}</h2>
          <p className="text-sm text-slate-500 dark:text-slate-400">{group.description}</p>
        </div>
        {dirty && <Badge tone="amber" dot>Unsaved changes</Badge>}
      </div>
      {!canManage && <Alert tone="info" className="mb-6">You can view these settings but not change them.</Alert>}

      {q.isLoading ? (
        <div className="space-y-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10" />)}</div>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); if (dirty && canManage) save.mutate(); }}>
          <div className="divide-y divide-slate-200 dark:divide-white/10">
            {group.sections.map((s) => (
              <FormSection key={s.title} title={s.title} description={s.description}>
                {s.fields.map((f) => (
                  <SettingField
                    key={f.key}
                    field={f}
                    value={values[f.key] ?? ''}
                    error={errors[f.key]}
                    disabled={!canManage}
                    onChange={(v) => { set(f.key, v); if (f.type === 'password') setCleared((c) => ({ ...c, [f.key]: false })); }}
                    secretSaved={f.type === 'password' && secretSaved(f.key) && !cleared[f.key]}
                    onClearSecret={() => { set(f.key, ''); setCleared((c) => ({ ...c, [f.key]: true })); }}
                  />
                ))}
              </FormSection>
            ))}
          </div>
          {canManage && (
            <div className="-mx-4 -mb-4 mt-2 flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:-mx-6 sm:-mb-6 sm:px-6 dark:border-white/10 dark:bg-white/[0.02]">
              <span className="mr-auto text-sm text-slate-500 dark:text-slate-400">
                {dirty ? `${changed.length} unsaved ${changed.length === 1 ? 'change' : 'changes'}` : 'All changes saved'}
              </span>
              <Button onClick={discard} disabled={!dirty || save.isPending}>Discard</Button>
              <Button type="submit" variant="primary" loading={save.isPending} disabled={!dirty}>Save</Button>
            </div>
          )}
        </form>
      )}
    </Card>
  );
}

function SettingField({ field: f, value, error, disabled, onChange, secretSaved, onClearSecret }: {
  field: FieldDef; value: string; error?: string; disabled: boolean; onChange: (v: string) => void; secretSaved: boolean; onClearSecret: () => void;
}) {
  const span = f.wide || f.type === 'bool' ? 'sm:col-span-6' : 'sm:col-span-3';
  if (f.type === 'bool') {
    return (
      <div className={span}>
        <Toggle label={f.label} description={f.hint} checked={value === '1'} onChange={(v) => onChange(v ? '1' : '0')} disabled={disabled} />
      </div>
    );
  }
  if (f.type === 'select') {
    const options = f.options ?? [];
    const withCurrent = value && !options.some((o) => o.value === value) ? [...options, { value, label: value }] : options;
    return (
      <Select wrapperClassName={span} label={f.label} hint={f.hint} error={error} value={value} disabled={disabled} options={withCurrent} onChange={(e) => onChange(e.target.value)} />
    );
  }
  if (f.type === 'password') {
    return (
      <Input
        wrapperClassName={span}
        label={f.label}
        type="password"
        autoComplete="new-password"
        value={value}
        error={error}
        disabled={disabled}
        placeholder={secretSaved ? MASK : 'Not set'}
        hint={secretSaved ? 'A value is saved. Type a new one to replace it.' : f.hint}
        onChange={(e) => onChange(e.target.value)}
        action={secretSaved && !disabled ? (
          <button type="button" onClick={onClearSecret} className="text-xs font-medium text-red-600 hover:underline dark:text-red-400">Clear</button>
        ) : undefined}
      />
    );
  }
  return (
    <Input
      wrapperClassName={span}
      label={f.label}
      type={f.type ?? 'text'}
      min={f.type === 'number' ? 0 : undefined}
      value={value}
      error={error}
      hint={f.hint}
      placeholder={f.placeholder}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
    />
  );
}
