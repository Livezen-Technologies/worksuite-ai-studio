import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BellIcon, CheckCircleIcon, CheckIcon, ExclamationTriangleIcon, InformationCircleIcon, MegaphoneIcon, PaperAirplaneIcon, XCircleIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, EmptyState, ErrorState, FormModal, IconButton, Input, PageHeader, Select, SkeletonRows, Tabs, Textarea, Toggle, cx, useToast } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago, dateTime } from '../lib/format';

type Level = 'info' | 'success' | 'warning' | 'error';
interface Note { id: number; user_id: number | null; type: string; title: string; body: string | null; level: Level; read_at: string | null; created_at: string }
type Report = Record<string, string> | null;

const icons = { info: InformationCircleIcon, success: CheckCircleIcon, warning: ExclamationTriangleIcon, error: XCircleIcon };
const colors: Record<Level, string> = { info: 'text-blue-500 dark:text-blue-400', success: 'text-emerald-500 dark:text-emerald-400', warning: 'text-amber-500 dark:text-amber-400', error: 'text-red-500 dark:text-red-400' };
const describe = (r: Report) => (r ? Object.entries(r).map(([k, v]) => (k === 'none' ? v : `${k}: ${v}`)).join(', ') : undefined);

function Broadcast({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [level, setLevel] = useState<Level>('info');
  const [channels, setChannels] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const send = useMutation({
    mutationFn: () => post<{ channels: Report }>('/notifications/broadcast', { title: title.trim(), body, level, channels }),
    onSuccess: (d) => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
      toast.success('Notification broadcast', d?.channels ? `Channels: ${describe(d.channels)}` : undefined);
      setTitle(''); setBody(''); setLevel('info'); setChannels(false); setErrors({}); onClose();
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });
  return (
    <FormModal open={open} onClose={onClose} title="Broadcast a notification" description="Every user sees it in their feed." submitLabel="Broadcast" submitting={send.isPending}
      onSubmit={() => { if (!title.trim()) { setErrors({ title: 'A title is required.' }); return; } send.mutate(); }}>
      <div className="space-y-4">
        <Input label="Title" required maxLength={191} value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} autoFocus />
        <Textarea label="Message" rows={3} value={body} onChange={(e) => setBody(e.target.value)} error={errors.body} />
        <Select label="Level" value={level} onChange={(e) => setLevel(e.target.value as Level)}
          options={[{ value: 'info', label: 'Info' }, { value: 'success', label: 'Success' }, { value: 'warning', label: 'Warning' }, { value: 'error', label: 'Error' }]} />
        <Toggle checked={channels} onChange={setChannels} label="Also send to external channels" description="Email, Slack, Discord, Telegram and the generic webhook, as configured in Settings." />
      </div>
    </FormModal>
  );
}

export default function Notifications() {
  const { can } = useAuth();
  const manage = can('notifications.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  const [broadcast, setBroadcast] = useState(false);

  const q = useQuery({ queryKey: ['notifications'], queryFn: () => get<{ rows: Note[]; unread: number }>('/notifications') });
  const mark = useMutation({
    mutationFn: (id?: number) => post('/notifications/read', id ? { id } : {}),
    onSuccess: (_d, id) => { qc.invalidateQueries({ queryKey: ['notifications'] }); if (!id) toast.success('All notifications marked as read'); },
    onError: (e) => toast.error(e),
  });
  const test = useMutation({
    mutationFn: () => post<{ channels: Report }>('/notifications/test'),
    onSuccess: (d) => toast.success('Test dispatched', describe(d?.channels ?? null)),
    onError: (e) => toast.error(e),
  });

  const rows = (q.data?.rows ?? []).filter((n) => filter === 'all' || !n.read_at);
  const unread = q.data?.unread ?? 0;

  return (
    <>
      <PageHeader title="Notifications" description="Your in-app feed, plus broadcasts to everyone and external channels."
        actions={<>
          <Button variant="secondary" icon={CheckIcon} disabled={!unread} loading={mark.isPending && mark.variables === undefined} onClick={() => mark.mutate(undefined)}>Mark all read</Button>
          {manage && <Button variant="secondary" icon={PaperAirplaneIcon} loading={test.isPending} onClick={() => test.mutate()}>Test channels</Button>}
          {manage && <Button icon={MegaphoneIcon} onClick={() => setBroadcast(true)}>Broadcast</Button>}
        </>} />
      <Card>
        <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10">
          <Tabs tabs={[{ key: 'all', label: 'All', count: q.data?.rows.length }, { key: 'unread', label: 'Unread', count: unread }]} value={filter} onChange={(k) => setFilter(k as 'all' | 'unread')} />
        </div>
        {q.isLoading ? <div className="p-4"><SkeletonRows rows={5} cols={2} /></div>
          : q.error ? <ErrorState error={q.error} onRetry={() => q.refetch()} />
          : !rows.length ? (
            <EmptyState icon={BellIcon} title={filter === 'unread' ? 'You are all caught up' : 'No notifications yet'}
              description={filter === 'unread' ? 'Nothing unread.' : 'Alerts and broadcasts will show up here.'}
              action={filter === 'unread' ? <Button variant="secondary" onClick={() => setFilter('all')}>Show all</Button> : manage ? <Button icon={MegaphoneIcon} onClick={() => setBroadcast(true)}>Send a broadcast</Button> : undefined} />
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-white/5">
              {rows.map((n) => {
                const Icon = icons[n.level] ?? InformationCircleIcon;
                return (
                  <li key={n.id} className={cx('flex gap-3 px-4 py-4 sm:px-6', n.read_at && 'opacity-60')}>
                    <Icon className={cx('mt-0.5 h-5 w-5 shrink-0', colors[n.level] ?? colors.info)} />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                        {n.title}
                        {!n.read_at && <span className="h-2 w-2 rounded-full bg-brand-600 dark:bg-brand-400" aria-label="Unread" />}
                      </div>
                      {n.body && <p className="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{n.body}</p>}
                      <div className="mt-1 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <span title={dateTime(n.created_at)}>{ago(n.created_at)}</span>
                        {n.user_id === null && <Badge tone="purple">Broadcast</Badge>}
                      </div>
                    </div>
                    {!n.read_at && <IconButton label="Mark as read" icon={CheckIcon} variant="ghost" size="sm" disabled={mark.isPending && mark.variables === n.id} onClick={() => mark.mutate(n.id)} />}
                  </li>
                );
              })}
            </ul>
          )}
      </Card>
      {manage && <Broadcast open={broadcast} onClose={() => setBroadcast(false)} />}
    </>
  );
}
