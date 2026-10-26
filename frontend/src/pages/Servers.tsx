import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import {
  ArrowPathIcon, ChartBarIcon, CommandLineIcon, CpuChipIcon, HeartIcon, Squares2X2Icon, PencilSquareIcon, PlusIcon, ServerStackIcon, TrashIcon, EyeIcon,
  PowerIcon, ArrowUpTrayIcon, ArrowPathRoundedSquareIcon,
} from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState, PageHeader, StatusBadge, Toolbar,
  SearchInput, StatCard, useToast, type Column,
} from '../components/ui';
import { del, get, post } from '../lib/api';
import { ago, compact, num } from '../lib/format';
import { useAuth } from '../lib/auth';
import { HealthScore, KINDS, type ControlAction, type Server } from './Servers/shared';
import ServerForm from './Servers/ServerForm';
import ServerDetail from './Servers/ServerDetail';
import EnrolNode from './Servers/EnrolNode';

export default function Servers() {
  const qc = useQueryClient();
  const toast = useToast();
  const { can } = useAuth();
  const manage = can('servers.manage');
  const [params, setParams] = useSearchParams();
  const openId = Number(params.get('server')) || null;
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<Server | null | undefined>(undefined); // undefined = closed, null = new
  const [removing, setRemoving] = useState<Server | null>(null);
  const [control, setControl] = useState<{ server: Server; action: ControlAction } | null>(null);
  const [enrolling, setEnrolling] = useState(false);

  const list = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers') });
  const summary = useQuery({ queryKey: ['servers', 'summary'], queryFn: () => get<FleetSummary>('/servers/summary'), refetchInterval: 60_000 });
  const t = summary.data;
  const invalidate = () => qc.invalidateQueries({ queryKey: ['servers'] });

  const health = useMutation({
    mutationFn: (id: number) => post<{ status: string; health_score: number }>(`/servers/${id}/health`),
    onSuccess: (r, id) => {
      toast.success('Health check complete', r ? `Status ${r.status}, score ${r.health_score}/100` : undefined);
      invalidate();
      qc.invalidateQueries({ queryKey: ['server', id] });
    },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/servers/${id}`),
    onSuccess: () => { toast.success('Server removed'); setRemoving(null); invalidate(); },
    onError: (e) => toast.error(e),
  });
  const ctl = useMutation({
    mutationFn: ({ id, action }: { id: number; action: ControlAction }) => post(`/servers/${id}/${action}`),
    onSuccess: () => { toast.success('Command sent to the node'); setControl(null); },
    onError: (e) => { toast.error(e); setControl(null); },
  });

  const open = (id: number | null) => {
    const p = new URLSearchParams(params);
    if (id) p.set('server', String(id)); else p.delete('server');
    setParams(p, { replace: true });
  };

  const q = search.trim().toLowerCase();
  const rows = (list.data ?? []).filter((s) =>
    !q || [s.name, s.api_url, s.hostname, s.ip_address, s.description, s.gpu_model].some((v) => v?.toLowerCase().includes(q)));
  const counts = (list.data ?? []).reduce<Record<string, number>>((a, s) => ({ ...a, [s.status]: (a[s.status] ?? 0) + 1 }), {});

  const columns: Column<Server>[] = [
    {
      key: 'name', header: 'Server', cell: (s) => {
        const kind = s.kind ?? 'ollama';
        const k = KINDS[kind];
        return (
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium text-slate-900 dark:text-white">{s.name}</span>
              {kind !== 'ollama' && <Badge tone={k.tone}>{k.label}</Badge>}
              {Number(s.is_local) === 1 && <Badge>local</Badge>}
            </div>
            <div className="truncate text-xs text-slate-500 dark:text-slate-400">{kind !== 'ollama' ? s.api_url || k.base : s.api_url}</div>
          </div>
        );
      },
    },
    { key: 'status', header: 'Status', cell: (s) => <StatusBadge status={s.status} /> },
    { key: 'health', header: 'Health', cell: (s) => <HealthScore score={s.health_score} />, hideBelow: 'sm' },
    {
      key: 'specs', header: 'Specs', hideBelow: 'lg', cell: (s) => (
        <div className="text-xs text-slate-500 dark:text-slate-400">
          <div>{s.cpu_cores ? `${s.cpu_cores} cores` : '—'}{s.ram_gb ? ` · ${Number(s.ram_gb)} GB RAM` : ''}</div>
          <div>{s.gpu_model || ((s.kind ?? 'ollama') === 'ollama' ? 'CPU-only' : 'Hosted API')}{s.ollama_version ? ` · Ollama ${s.ollama_version}` : ''}</div>
        </div>
      ),
    },
    {
      key: 'seen', header: 'Last seen', hideBelow: 'md',
      cell: (s) => <span className="text-xs text-slate-500 dark:text-slate-400">{s.last_heartbeat_at ? ago(s.last_heartbeat_at) : 'never'}</span>,
    },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (s) => {
        const ollama = (s.kind ?? 'ollama') === 'ollama';
        return (
          <div onClick={(e) => e.stopPropagation()}>
            <ActionMenu actions={[
              { label: 'View details', icon: EyeIcon, onClick: () => open(s.id) },
              { label: 'Run health check', icon: HeartIcon, onClick: () => health.mutate(s.id), hidden: !manage, disabled: health.isPending },
              { label: 'Edit', icon: PencilSquareIcon, onClick: () => setEditing(s), hidden: !manage },
              { label: 'Restart Ollama', icon: ArrowPathRoundedSquareIcon, onClick: () => setControl({ server: s, action: 'restart' }), hidden: !manage || !ollama },
              { label: 'Update Ollama', icon: ArrowUpTrayIcon, onClick: () => setControl({ server: s, action: 'update' }), hidden: !manage || !ollama },
              { label: 'Shut down node', icon: PowerIcon, onClick: () => setControl({ server: s, action: 'shutdown' }), hidden: !manage || !ollama, danger: true },
              { label: 'Remove', icon: TrashIcon, onClick: () => setRemoving(s), hidden: !manage, danger: true },
            ]} />
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title="Servers"
        description="Ollama nodes and connected LLM providers."
        meta={list.data ? (
          <span className="text-sm text-slate-500 dark:text-slate-400">
            {list.data.length} total · {counts.online ?? 0} online
            {counts.degraded ? ` · ${counts.degraded} degraded` : ''}{counts.offline ? ` · ${counts.offline} offline` : ''}
          </span>
        ) : undefined}
        actions={<>
          <Button icon={ArrowPathIcon} loading={list.isRefetching} onClick={() => list.refetch()}>Refresh</Button>
          {manage && <Button icon={CommandLineIcon} onClick={() => setEnrolling(true)}>Connect a node</Button>}
          {manage && <Button variant="primary" icon={PlusIcon} onClick={() => setEditing(null)}>Add server</Button>}
        </>}
      />
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
        <StatCard name="Servers" icon={ServerStackIcon} loading={summary.isLoading} value={t ? num(t.servers) : '—'} hint={t ? `${t.online} online` : undefined} />
        <StatCard name="CPU cores" icon={CpuChipIcon} loading={summary.isLoading} value={t ? num(t.cpu_cores) : '—'} hint={t ? `${num(t.ram_gb)} GB RAM in total` : undefined} />
        <StatCard name="GPUs" icon={Squares2X2Icon} loading={summary.isLoading} value={t ? num(t.gpus) : '—'} hint={t ? (t.vram_gb ? `${num(t.vram_gb)} GB VRAM in total` : 'No GPU nodes yet') : undefined} />
        <StatCard name="CUDA cores" icon={Squares2X2Icon} loading={summary.isLoading} value={t ? num(t.cuda_cores) : '—'}
          hint={t ? (t.gpus ? `${t.cuda_known} of ${t.gpus} GPU${t.gpus === 1 ? '' : 's'} identified` : 'No GPU nodes yet') : undefined} />
        <StatCard name="Requests (24 h)" icon={ChartBarIcon} loading={summary.isLoading} value={t ? num(t.requests_24h) : '—'} hint={t ? `${compact(t.tokens_24h)} tokens` : undefined} />
      </div>
      <Card>
        <Toolbar><SearchInput value={search} onChange={setSearch} placeholder="Search servers…" className="w-full sm:w-72" /></Toolbar>
        <DataTable
          columns={columns}
          rows={list.data ? rows : undefined}
          rowKey={(s) => s.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => list.refetch()}
          onRowClick={(s) => open(s.id)}
          empty={q ? <EmptyState title="No matches" description="No server matches your search." /> : (
            <EmptyState
              icon={ServerStackIcon}
              title="No servers registered yet"
              description="Register an Ollama node, or connect OpenAI, Anthropic or Gemini as a model source."
              action={manage ? <Button variant="primary" icon={PlusIcon} onClick={() => setEditing(null)}>Add your first server</Button> : undefined}
            />
          )}
        />
      </Card>

      {enrolling && <EnrolNode onClose={() => setEnrolling(false)} />}
      {editing !== undefined && <ServerForm server={editing} onClose={() => setEditing(undefined)} />}
      {openId && (
        <ServerDetail
          id={openId}
          onClose={() => open(null)}
          onEdit={(s) => setEditing(s)}
          onControl={(server, action) => setControl({ server, action })}
        />
      )}

      <ConfirmDialog
        open={!!removing}
        onClose={() => setRemoving(null)}
        onConfirm={() => removing && remove.mutate(removing.id)}
        busy={remove.isPending}
        title="Remove this server?"
        message={<>“{removing?.name}” will stop being used as a model source. Nothing on the node itself is changed.</>}
        confirmLabel="Remove"
      />
      <ConfirmDialog
        open={!!control}
        onClose={() => setControl(null)}
        onConfirm={() => control && ctl.mutate({ id: control.server.id, action: control.action })}
        busy={ctl.isPending}
        tone={control?.action === 'shutdown' ? 'danger' : 'primary'}
        title={control ? `${CONTROL_LABEL[control.action]} on ${control.server.name}?` : ''}
        message={control?.action === 'shutdown'
          ? 'The node stops serving requests until it is started again. Node control needs the node agent installed on the server.'
          : 'Requests running on this node may fail while it does this. Node control needs the node agent installed on the server.'}
        confirmLabel={control ? CONTROL_LABEL[control.action] : 'Confirm'}
      />
    </>
  );
}

interface FleetSummary {
  servers: number; online: number; cpu_cores: number; ram_gb: number; gpus: number; vram_gb: number; cuda_cores: number; cuda_known: number; requests_24h: number; tokens_24h: number;
}

const CONTROL_LABEL: Record<ControlAction, string> = { restart: 'Restart Ollama', update: 'Update Ollama', shutdown: 'Shut down' };
