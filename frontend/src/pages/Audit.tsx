import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ShieldCheckIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CodeBlock, DataTable, DescriptionList, EmptyState, Modal, PageHeader, Pagination, SearchInput, Select, Toolbar, type BadgeTone, type Column } from '../components/ui';
import { get, type Paged } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime } from '../lib/format';

interface AuditRow {
  id: number; user_id: number | null; user_name: string | null; action: string; method: string | null; path: string | null;
  status: number | null; ip: string | null; meta: string | null; created_at: string;
}

const tone = (s: number | null): BadgeTone => (!s ? 'gray' : s < 300 ? 'green' : s < 500 ? 'amber' : 'red');
const methodTone: Record<string, BadgeTone> = { POST: 'green', PUT: 'blue', PATCH: 'blue', DELETE: 'red', GET: 'gray' };
const RANGES = [{ value: 'today', label: 'Today' }, { value: 'week', label: 'Last 7 days' }, { value: 'month', label: 'Last 30 days' }, { value: 'all', label: 'All time' }];

function prettyMeta(meta: string | null): string | null {
  if (!meta) return null;
  try { return JSON.stringify(JSON.parse(meta), null, 2); } catch { return meta; }
}

export default function Audit() {
  const { can } = useAuth();
  const [range, setRange] = useState('week');
  const [action, setAction] = useState('');
  const [userId, setUserId] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<AuditRow | null>(null);

  const users = useQuery({ queryKey: ['users'], queryFn: () => get<{ id: number; name: string }[]>('/users'), enabled: can('users.view'), retry: false });
  const q = useQuery({
    queryKey: ['audit', range, action, userId, page],
    queryFn: () => get<Paged<AuditRow>>('/audit', { range: range === 'all' ? '' : range, action, user_id: userId, page, per_page: 40 }),
    placeholderData: keepPreviousData,
  });
  const reset = (fn: () => void) => { fn(); setPage(1); };
  const filtered = action || userId || range !== 'week';
  const clear = (r = 'week') => reset(() => { setRange(r); setAction(''); setUserId(''); });

  const columns: Column<AuditRow>[] = [
    { key: 'time', header: 'Time', cell: (r) => <span className="whitespace-nowrap text-sm text-slate-600 dark:text-slate-300">{dateTime(r.created_at)}</span> },
    { key: 'user', header: 'User', cell: (r) => r.user_name || <span className="text-slate-400 dark:text-slate-500">System</span> },
    { key: 'action', header: 'Action', cell: (r) => <span className="font-medium text-slate-900 dark:text-white">{r.action}</span> },
    { key: 'method', header: 'Method', hideBelow: 'md', cell: (r) => r.method ? <Badge tone={methodTone[r.method] ?? 'gray'}>{r.method}</Badge> : '—' },
    { key: 'path', header: 'Path', hideBelow: 'lg', cell: (r) => <span className="font-mono text-xs text-slate-600 dark:text-slate-300">{r.path || '—'}</span> },
    { key: 'status', header: 'Status', hideBelow: 'sm', cell: (r) => <Badge tone={tone(r.status)}>{r.status ?? '—'}</Badge> },
    { key: 'ip', header: 'IP', hideBelow: 'lg', cell: (r) => <span className="font-mono text-xs">{r.ip || '—'}</span> },
  ];
  const meta = open ? prettyMeta(open.meta) : null;

  return (
    <>
      <PageHeader title="Audit log" description="Who changed what, and when." />
      <Card>
        <Toolbar right={filtered && <Button variant="ghost" size="sm" onClick={() => clear()}>Clear filters</Button>}>
          <SearchInput value={action} onChange={(v) => reset(() => setAction(v))} placeholder="Filter by action…" />
          {users.data && <Select aria-label="User" options={[{ value: '', label: 'All users' }, ...users.data.map((u) => ({ value: String(u.id), label: u.name }))]} value={userId} onChange={(e) => reset(() => setUserId(e.target.value))} />}
          <Select aria-label="Date range" options={RANGES} value={range} onChange={(e) => reset(() => setRange(e.target.value))} />
        </Toolbar>
        <DataTable columns={columns} rows={q.data?.rows} rowKey={(r) => r.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()} onRowClick={setOpen} dense
          empty={<EmptyState icon={ShieldCheckIcon} title="No audit entries" description={filtered ? 'Nothing matches these filters.' : 'Changes made in AI Studio will be recorded here.'}
            action={filtered ? <Button variant="secondary" onClick={() => clear('all')}>Show everything</Button> : undefined} />} />
        {q.data && q.data.total > 0 && <Pagination page={q.data.page} perPage={q.data.per_page} total={q.data.total} onPage={setPage} />}
      </Card>

      <Modal open={!!open} onClose={() => setOpen(null)} size="lg" title={open?.action ?? ''} description={open ? dateTime(open.created_at) : undefined}>
        {open && (
          <div className="space-y-4">
            <DescriptionList items={[
              { label: 'User', value: open.user_name ? `${open.user_name}${open.user_id ? ` (#${open.user_id})` : ''}` : 'System' },
              { label: 'Request', value: <span className="font-mono text-xs">{[open.method, open.path].filter(Boolean).join(' ') || '—'}</span> },
              { label: 'Status', value: <Badge tone={tone(open.status)}>{open.status ?? '—'}</Badge> },
              { label: 'IP address', value: open.ip || '—' },
            ]} />
            <div>
              <div className="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">Details</div>
              {meta ? <CodeBlock>{meta}</CodeBlock> : <p className="text-sm text-slate-500 dark:text-slate-400">No extra details were recorded.</p>}
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}
