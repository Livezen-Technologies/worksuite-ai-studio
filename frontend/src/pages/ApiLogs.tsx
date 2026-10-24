import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { DocumentMagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, DataTable, DescriptionList, EmptyState, Input, Modal, PageHeader, Pagination, Select, Toolbar, type BadgeTone, type Column } from '../components/ui';
import { get, type Paged } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime, ms, num } from '../lib/format';

export interface ApiLog {
  id: number; api_key_id: number | null; key_name?: string | null; endpoint: string; model?: string | null; server_name?: string | null;
  method?: string; prompt_tokens?: number | null; completion_tokens?: number | null; total_tokens?: number | null; processing_ms?: number | null;
  status: number; client_ip?: string | null; country?: string | null; error?: string | null; created_at: string;
}

const statusTone = (s: number): BadgeTone => (s < 300 ? 'green' : s < 500 ? 'amber' : 'red');
const RANGES = [{ value: 'today', label: 'Today' }, { value: 'week', label: 'Last 7 days' }, { value: 'month', label: 'Last 30 days' }, { value: 'all', label: 'All time' }];
const STATUSES = [{ value: '', label: 'Any status' }, { value: '200', label: '200 OK' }, { value: '403', label: '403 Forbidden' }, { value: '429', label: '429 Rate limited' }, { value: '502', label: '502 Bad gateway' }, { value: '503', label: '503 Unavailable' }];

export default function ApiLogs() {
  const { can } = useAuth();
  const [range, setRange] = useState('week');
  const [status, setStatus] = useState('');
  const [keyId, setKeyId] = useState('');
  const [model, setModel] = useState('');
  const [modelDraft, setModelDraft] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<ApiLog | null>(null);

  const keys = useQuery({ queryKey: ['apikeys'], queryFn: () => get<{ id: number; name: string }[]>('/apikeys'), enabled: can('apikeys.view'), retry: false });
  const q = useQuery({
    queryKey: ['apilogs', range, status, keyId, model, page],
    queryFn: () => get<Paged<ApiLog>>('/apilogs', { range: range === 'all' ? '' : range, status, api_key_id: keyId, model, page, per_page: 30 }),
    placeholderData: keepPreviousData,
  });
  const reset = (fn: () => void) => { fn(); setPage(1); };
  const filtered = status || keyId || model || range !== 'week';

  const columns: Column<ApiLog>[] = [
    { key: 'time', header: 'Time', cell: (r) => <span className="whitespace-nowrap text-sm text-slate-600 dark:text-slate-300">{dateTime(r.created_at)}</span> },
    { key: 'status', header: 'Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
    { key: 'endpoint', header: 'Endpoint', cell: (r) => <span className="font-mono text-xs text-slate-800 dark:text-slate-200">/{r.endpoint}</span> },
    { key: 'key', header: 'Key', hideBelow: 'md', cell: (r) => r.key_name || <span className="text-slate-400 dark:text-slate-500">—</span> },
    { key: 'model', header: 'Model', hideBelow: 'sm', cell: (r) => <span className="font-mono text-xs">{r.model || '—'}</span> },
    { key: 'node', header: 'Node', hideBelow: 'lg', cell: (r) => r.server_name || '—' },
    { key: 'tokens', header: 'Tokens', align: 'right', hideBelow: 'md', cell: (r) => num(r.total_tokens) },
    { key: 'latency', header: 'Latency', align: 'right', hideBelow: 'md', cell: (r) => ms(r.processing_ms) },
    { key: 'ip', header: 'Client IP', hideBelow: 'lg', cell: (r) => <span className="font-mono text-xs">{r.client_ip || '—'}</span> },
    { key: 'error', header: 'Error', hideBelow: 'lg', cell: (r) => r.error ? <span className="line-clamp-1 max-w-xs text-xs text-red-600 dark:text-red-400">{r.error}</span> : '' },
  ];

  return (
    <>
      <PageHeader title="API logs" description="Every request made through the /api/v1 gateway." />
      <Card>
        <Toolbar right={filtered && <Button variant="ghost" size="sm" onClick={() => reset(() => { setRange('week'); setStatus(''); setKeyId(''); setModel(''); setModelDraft(''); })}>Clear filters</Button>}>
          <Select aria-label="Date range" options={RANGES} value={range} onChange={(e) => reset(() => setRange(e.target.value))} />
          <Select aria-label="Status" options={STATUSES} value={status} onChange={(e) => reset(() => setStatus(e.target.value))} />
          {keys.data && <Select aria-label="API key" options={[{ value: '', label: 'All keys' }, ...keys.data.map((k) => ({ value: String(k.id), label: k.name }))]} value={keyId} onChange={(e) => reset(() => setKeyId(e.target.value))} />}
          <form onSubmit={(e) => { e.preventDefault(); reset(() => setModel(modelDraft.trim())); }}>
            <Input aria-label="Model" placeholder="Exact model name, Enter" value={modelDraft} onChange={(e) => setModelDraft(e.target.value)} onBlur={() => modelDraft.trim() !== model && reset(() => setModel(modelDraft.trim()))} />
          </form>
        </Toolbar>
        <DataTable columns={columns} rows={q.data?.rows} rowKey={(r) => r.id} loading={q.isLoading} error={q.error} onRetry={() => q.refetch()} onRowClick={setOpen} dense
          empty={<EmptyState icon={DocumentMagnifyingGlassIcon} title="No gateway requests" description={filtered ? 'Nothing matches these filters.' : 'Requests made with an API key will appear here.'}
            action={filtered ? <Button variant="secondary" onClick={() => reset(() => { setRange('all'); setStatus(''); setKeyId(''); setModel(''); setModelDraft(''); })}>Show everything</Button> : undefined} />} />
        {q.data && q.data.total > 0 && <Pagination page={q.data.page} perPage={q.data.per_page} total={q.data.total} onPage={setPage} />}
      </Card>

      <Modal open={!!open} onClose={() => setOpen(null)} size="lg" title={open ? `${open.method ?? 'POST'} /api/v1/${open.endpoint}` : ''} description={open ? dateTime(open.created_at) : undefined}>
        {open && (
          <div className="space-y-4">
            <DescriptionList items={[
              { label: 'Status', value: <Badge tone={statusTone(open.status)}>{open.status}</Badge> },
              { label: 'API key', value: open.key_name || (open.api_key_id ? `#${open.api_key_id}` : '—') },
              { label: 'Model', value: <span className="font-mono text-xs">{open.model || '—'}</span> },
              { label: 'Node', value: open.server_name || '—' },
              { label: 'Tokens', value: `${num(open.prompt_tokens)} prompt + ${num(open.completion_tokens)} completion = ${num(open.total_tokens)}` },
              { label: 'Processing time', value: ms(open.processing_ms) },
              { label: 'Client', value: [open.client_ip, open.country].filter(Boolean).join(' · ') || '—' },
              { label: 'Request ID', value: `#${open.id}` },
            ]} />
            {open.error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-600/10 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20">{open.error}</div>}
          </div>
        )}
      </Modal>
    </>
  );
}
