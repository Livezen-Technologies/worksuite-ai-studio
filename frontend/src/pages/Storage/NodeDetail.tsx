import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { TrashIcon } from '@heroicons/react/24/outline';
import { Alert, Badge, Button, Card, CardHeader, Input, Modal, Select, StatusBadge, useToast } from '../../components/ui';
import { del, get, post } from '../../lib/api';
import { ago, bytes, dateTime } from '../../lib/format';
import { KIND, STATUS_TONE, n2, type Allocation, type AlertRow, type Check, type Node } from './shared';

function Spark({ values, max, color }: { values: (number | null)[]; max?: number; color: string }) {
  const v = values.map((x) => x ?? 0);
  const top = max ?? Math.max(1, ...v);
  if (v.length < 2) return <p className="text-xs text-slate-400">Not enough checks yet.</p>;
  const pts = v.map((x, i) => `${(i / (v.length - 1)) * 100},${38 - (x / top) * 34}`).join(' ');
  return <svg viewBox="0 0 100 40" preserveAspectRatio="none" className="h-16 w-full" aria-hidden><polyline points={pts} fill="none" stroke={color} strokeWidth="1.2" vectorEffect="non-scaling-stroke" /></svg>;
}

export function NodeDetail({ id, manage, onClose }: { id: number; manage: boolean; onClose: () => void }) {
  const q = useQuery({ queryKey: ['storage-node', id], queryFn: () => get<{ node: Node; checks: Check[]; allocations: Allocation[]; alerts: (AlertRow & { resolved_at: string | null })[] }>(`/storage/nodes/${id}`) });
  const qc = useQueryClient();
  const toast = useToast();
  const [scopeType, setScopeType] = useState('app');
  const [scopeId, setScopeId] = useState('');
  const [quota, setQuota] = useState('');
  const add = useMutation({
    mutationFn: () => post(`/storage/nodes/${id}/allocations`, { scope_type: scopeType, scope_id: scopeId, quota_gb: quota }),
    onSuccess: () => { setScopeId(''); setQuota(''); qc.invalidateQueries({ queryKey: ['storage-node', id] }); qc.invalidateQueries({ queryKey: ['storage'] }); },
    onError: (e) => toast.error(e),
  });
  const drop = useMutation({ mutationFn: (a: number) => del(`/storage/allocations/${a}`), onSuccess: () => qc.invalidateQueries({ queryKey: ['storage-node', id] }), onError: (e) => toast.error(e) });
  const n = q.data?.node;
  const checks = q.data?.checks ?? [];
  return (
    <Modal open onClose={onClose} size="xl" expandKey="storage-node" title={n ? <span className="flex flex-wrap items-center gap-2">{n.name}<Badge tone={STATUS_TONE[n.status]}>{n.status}</Badge><StatusBadge status={n.health} /></span> : 'Storage node'}
      description={n ? `${KIND[n.kind].label} · ${n.kind === 's3' ? `${n.config.bucket} at ${n.config.endpoint}` : n.config.path}` : undefined}>
      {!n ? <p className="text-sm text-slate-500">Loading…</p> : (
        <div className="space-y-6">
          {n.last_error && n.health === 'down' && <Alert tone="danger" title="Last check failed">{n.last_error}</Alert>}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[['Used', n.capacity_bytes ? `${bytes(n.used_bytes ?? 0)} (${n.used_pct}%)` : n.used_bytes != null ? bytes(n.used_bytes) : '—'], ['Free', n.free_bytes != null ? bytes(n.free_bytes) : n.capacity_bytes && n.used_bytes != null ? bytes(n.capacity_bytes - n.used_bytes) : '—'],
              ['Write / read', `${n2(n.write_mbps, '')}/ ${n2(n.read_mbps, 'MB/s')}`], ['Response', n2(n.latency_ms, 'ms')]].map(([k, v]) => (
              <div key={k} className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                <div className="text-xs text-slate-500 dark:text-slate-400">{k}</div><div className="mt-1 text-sm font-semibold break-words text-slate-900 dark:text-white">{v}</div>
              </div>
            ))}
          </div>
          <div className="grid gap-6 lg:grid-cols-3">
            <Card><CardHeader title="Used space, 7 days" /><div className="p-4"><Spark values={checks.map((c) => (c.used_bytes && (n.quota_bytes || c.total_bytes) ? (c.used_bytes / (n.quota_bytes || c.total_bytes!)) * 100 : null))} max={100} color="#0A6CFF" /></div></Card>
            <Card><CardHeader title="Write speed (MB/s)" /><div className="p-4"><Spark values={checks.map((c) => c.write_mbps)} color="#00DAF2" /></div></Card>
            <Card><CardHeader title="Response time (ms)" /><div className="p-4"><Spark values={checks.map((c) => c.latency_ms)} color="#f59e0b" /></div></Card>
          </div>
          <Card>
            <CardHeader title="Allocations" description="Which apps, users, services or organizations this node is assigned to, with an optional quota each." />
            <ul className="divide-y divide-slate-200 dark:divide-white/10">
              {(q.data?.allocations ?? []).map((a) => (
                <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm sm:px-6">
                  <span><Badge>{a.scope_type}</Badge> <span className="ml-2 font-medium text-slate-900 dark:text-white">{a.scope_id}</span>{a.quota_bytes ? <span className="ml-2 text-slate-500">· {bytes(a.quota_bytes)}</span> : null}</span>
                  {manage && <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => drop.mutate(a.id)}>Remove</Button>}
                </li>
              ))}
              {!(q.data?.allocations.length) && <li className="px-4 py-3 text-sm text-slate-500 sm:px-6">Not allocated — available to everything.</li>}
            </ul>
            {manage && (
              <div className="grid gap-3 border-t border-slate-200 p-4 sm:grid-cols-4 sm:px-6 dark:border-white/10">
                <Select label="Assign to" value={scopeType} onChange={(e) => setScopeType(e.target.value)} options={[{ value: 'app', label: 'App' }, { value: 'service', label: 'Service' }, { value: 'user', label: 'User' }, { value: 'organization', label: 'Organization' }]} />
                <Input label="Which" value={scopeId} onChange={(e) => setScopeId(e.target.value)} placeholder={scopeType === 'app' ? 'datasets, speech, models…' : scopeType === 'user' ? 'email or user id' : 'name'} />
                <Input label="Quota (GB)" type="number" value={quota} onChange={(e) => setQuota(e.target.value)} placeholder="optional" />
                <div className="flex items-end"><Button variant="primary" className="w-full" loading={add.isPending} disabled={!scopeId.trim()} onClick={() => add.mutate()}>Allocate</Button></div>
              </div>
            )}
          </Card>
          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <CardHeader title="Alert history" />
              <ul className="max-h-64 divide-y divide-slate-200 overflow-auto dark:divide-white/10">
                {(q.data?.alerts ?? []).map((a) => (
                  <li key={a.id} className="px-4 py-2.5 text-sm sm:px-6">
                    <Badge tone={a.level === 'error' ? 'red' : 'amber'}>{a.level}</Badge> <span className="text-slate-700 dark:text-slate-300">{a.message}</span>
                    <p className="text-xs text-slate-500">{dateTime(a.opened_at)}{a.resolved_at ? ` — resolved ${ago(a.resolved_at)}` : ' — open'}</p>
                  </li>
                ))}
                {!(q.data?.alerts.length) && <li className="px-4 py-3 text-sm text-slate-500 sm:px-6">No alerts.</li>}
              </ul>
            </Card>
            <Card>
              <CardHeader title="Lifecycle" />
              <ul className="max-h-64 divide-y divide-slate-200 overflow-auto dark:divide-white/10">
                {[...n.status_history].reverse().map((h, i) => (
                  <li key={i} className="px-4 py-2.5 text-sm sm:px-6"><span className="font-medium text-slate-900 dark:text-white">{h.from ? `${h.from} → ${h.to}` : h.to}</span>
                    <p className="text-xs text-slate-500">{dateTime(h.at)}{h.reason ? ` — ${h.reason}` : ''}</p></li>
                ))}
              </ul>
            </Card>
          </div>
        </div>
      )}
    </Modal>
  );
}
