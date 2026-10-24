import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AdjustmentsHorizontalIcon, ArrowPathIcon, CpuChipIcon, PlayIcon, PlusIcon, StarIcon, StopIcon, TrashIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState, PageHeader, SearchInput, Select, StatusBadge,
  Toolbar, useToast, type Column,
} from '../components/ui';
import { del, get, post } from '../lib/api';
import { ago, bytes } from '../lib/format';
import { useAuth } from '../lib/auth';
import type { Server } from './Servers/shared';
import InstallModal from './Models/InstallModal';
import ConfigModal from './Models/ConfigModal';

interface Model {
  id: number; server_id: number; name: string; family: string | null; parameter_size: string | null; quantization: string | null;
  format: string | null; size_bytes: number | string | null; context_length: number | null; is_default: number | string;
  status: 'available' | 'loading' | 'running' | 'pulling' | 'error'; synced_at: string | null;
  server_name: string; server_status: string;
}

type Action = 'load' | 'unload' | 'default';
const DONE: Record<Action, string> = { load: 'Model loaded into memory', unload: 'Model unloaded', default: 'Default model set' };

export default function Models() {
  const qc = useQueryClient();
  const toast = useToast();
  const { can } = useAuth();
  const manage = can('models.manage');
  const [serverId, setServerId] = useState('');
  const [search, setSearch] = useState('');
  const [installing, setInstalling] = useState(false);
  const [configuring, setConfiguring] = useState<Model | null>(null);
  const [removing, setRemoving] = useState<Model | null>(null);

  const list = useQuery({ queryKey: ['models', serverId], queryFn: () => get<Model[]>('/models', { server_id: serverId }) });
  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers') });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['models'] });

  const sync = useMutation({
    mutationFn: () => post('/models/sync', serverId ? { server_id: Number(serverId) } : {}),
    onSuccess: () => { toast.success(serverId ? 'Models synced' : 'Sync complete'); invalidate(); },
    onError: (e) => toast.error(e),
  });
  const act = useMutation({
    mutationFn: ({ id, action }: { id: number; action: Action }) => post(`/models/${id}/${action}`),
    onSuccess: (_r, v) => { toast.success(DONE[v.action]); invalidate(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/models/${id}`),
    onSuccess: () => { toast.success('Model removed from node'); setRemoving(null); invalidate(); },
    onError: (e) => toast.error(e),
  });

  const q = search.trim().toLowerCase();
  const rows = (list.data ?? []).filter((m) => !q || [m.name, m.family, m.server_name].some((v) => v?.toLowerCase().includes(q)));
  const hasServers = (servers.data?.length ?? 0) > 0;

  const columns: Column<Model>[] = [
    {
      key: 'name', header: 'Model', cell: (m) => (
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-mono font-medium text-slate-900 dark:text-white">{m.name}</span>
            {Number(m.is_default) === 1 && <Badge tone="amber">default</Badge>}
          </div>
          <div className="text-xs text-slate-500 dark:text-slate-400">
            {m.family || '—'}{m.quantization ? ` · ${m.quantization}` : ''}{m.context_length ? ` · ${m.context_length.toLocaleString()} ctx` : ''}
          </div>
        </div>
      ),
    },
    { key: 'params', header: 'Params', hideBelow: 'sm', cell: (m) => m.parameter_size || '—' },
    { key: 'size', header: 'Size', hideBelow: 'sm', align: 'right', cell: (m) => (m.size_bytes ? bytes(m.size_bytes) : '—') },
    {
      key: 'node', header: 'Node', hideBelow: 'md', cell: (m) => (
        <div className="flex items-center gap-2 text-sm">
          <span className="text-slate-700 dark:text-slate-300">{m.server_name}</span>
          <StatusBadge status={m.server_status} />
        </div>
      ),
    },
    { key: 'status', header: 'Status', cell: (m) => <StatusBadge status={m.status} label={m.status === 'running' ? 'in memory' : undefined} /> },
    { key: 'synced', header: 'Synced', hideBelow: 'lg', cell: (m) => <span className="text-xs text-slate-500 dark:text-slate-400">{ago(m.synced_at)}</span> },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (m) => (
        <ActionMenu actions={[
          { label: manage ? 'Configure' : 'View configuration', icon: AdjustmentsHorizontalIcon, onClick: () => setConfiguring(m) },
          { label: 'Load into memory', icon: PlayIcon, onClick: () => act.mutate({ id: m.id, action: 'load' }), hidden: !manage, disabled: act.isPending || m.status === 'running' },
          { label: 'Unload', icon: StopIcon, onClick: () => act.mutate({ id: m.id, action: 'unload' }), hidden: !manage, disabled: act.isPending },
          { label: 'Set as default', icon: StarIcon, onClick: () => act.mutate({ id: m.id, action: 'default' }), hidden: !manage, disabled: act.isPending || Number(m.is_default) === 1 },
          { label: 'Delete from node', icon: TrashIcon, onClick: () => setRemoving(m), hidden: !manage, danger: true },
        ]} />
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Models"
        description="Models installed across your nodes and providers."
        actions={<>
          {manage && <Button icon={ArrowPathIcon} loading={sync.isPending} onClick={() => sync.mutate()}>{serverId ? 'Sync node' : 'Sync all'}</Button>}
          {manage && <Button variant="primary" icon={PlusIcon} disabled={!hasServers} onClick={() => setInstalling(true)}>Install model</Button>}
        </>}
      />
      <Card>
        <Toolbar right={<span className="text-sm text-slate-500 dark:text-slate-400">{list.data ? `${rows.length} model${rows.length === 1 ? '' : 's'}` : ''}</span>}>
          <SearchInput value={search} onChange={setSearch} placeholder="Search models…" className="w-full sm:w-64" />
          <Select aria-label="Node" value={serverId} onChange={(e) => setServerId(e.target.value)} wrapperClassName="w-full sm:w-56"
            options={[{ value: '', label: 'All nodes' }, ...(servers.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))]} />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={list.data ? rows : undefined}
          rowKey={(m) => m.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => list.refetch()}
          empty={q ? <EmptyState title="No matches" description="No model matches your search." /> : (
            <EmptyState
              icon={CpuChipIcon}
              title="No models yet"
              description={hasServers ? 'Install one from the catalogue, or sync to pick up models already on your nodes.' : 'Register a server first, then install or sync models.'}
              action={manage && hasServers ? (
                <div className="flex gap-2">
                  <Button icon={ArrowPathIcon} loading={sync.isPending} onClick={() => sync.mutate()}>Sync</Button>
                  <Button variant="primary" icon={PlusIcon} onClick={() => setInstalling(true)}>Browse and install</Button>
                </div>
              ) : undefined}
            />
          )}
        />
      </Card>

      <InstallModal
        open={installing}
        servers={servers.data ?? []}
        installed={(list.data ?? []).map((m) => m.name)}
        onClose={() => setInstalling(false)}
        onDone={(name) => { toast.success('Model installed', name); invalidate(); }}
      />
      {configuring && <ConfigModal model={configuring} readOnly={!manage} onClose={() => setConfiguring(null)} />}
      <ConfirmDialog
        open={!!removing}
        onClose={() => setRemoving(null)}
        onConfirm={() => removing && remove.mutate(removing.id)}
        busy={remove.isPending}
        title="Delete this model from the node?"
        message={<>“{removing?.name}” is deleted from {removing?.server_name}. You can install it again later.</>}
        confirmLabel="Delete"
      />
    </>
  );
}
