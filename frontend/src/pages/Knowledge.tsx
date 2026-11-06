import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpenIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState, FormModal, Input,
  PageHeader, SearchInput, Select, Toolbar, useToast,
  type Column,
} from '../components/ui';
import { ApiError, del, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime, num } from '../lib/format';
import { BaseDetail } from './Knowledge/BaseDetail';
import type { Base, Server } from './Knowledge/shared';

export default function Knowledge() {
  const qc = useQueryClient();
  const toast = useToast();
  const { can } = useAuth();
  const canManage = can('knowledge.manage');

  const [q, setQ] = useState('');
  const [openId, setOpenId] = useState<number | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState({ name: '', description: '', embed_model: 'nomic-embed-text', server_id: '', chunk_size: '800' });
  const [deleting, setDeleting] = useState<Base | null>(null);

  const list = useQuery({ queryKey: ['knowledge'], queryFn: () => get<Base[]>('/knowledge') });
  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers'), enabled: canManage && can('servers.view') });
  const refresh = () => qc.invalidateQueries({ queryKey: ['knowledge'] });

  const create = useMutation({
    mutationFn: () => post<Base>('/knowledge', {
      name: form.name.trim(), description: form.description.trim() || null, embed_model: form.embed_model.trim() || 'nomic-embed-text',
      server_id: form.server_id ? Number(form.server_id) : null, chunk_size: Number(form.chunk_size) || 800,
    }),
    onSuccess: (kb) => {
      toast.success('Knowledge base created');
      setCreateOpen(false);
      setForm({ name: '', description: '', embed_model: 'nomic-embed-text', server_id: '', chunk_size: '800' });
      void refresh();
      if (kb?.id) setOpenId(kb.id);
    },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/knowledge/${id}`),
    onSuccess: (_d, id) => { toast.success('Knowledge base deleted'); setDeleting(null); if (openId === id) setOpenId(null); void refresh(); },
    onError: (e) => toast.error(e),
  });
  const createErr = create.error instanceof ApiError ? create.error.fields : {};

  const rows = useMemo(() => {
    const t = q.trim().toLowerCase();
    return (list.data ?? []).filter((b) => !t || b.name.toLowerCase().includes(t) || (b.description ?? '').toLowerCase().includes(t));
  }, [list.data, q]);

  const columns: Column<Base>[] = [
    {
      key: 'name', header: 'Knowledge base', cell: (b) => (
        <div className="min-w-0">
          <div className="truncate">{b.name}</div>
          <div className="truncate text-xs font-normal text-slate-500 dark:text-slate-400">{b.description || '—'}</div>
        </div>
      ),
    },
    { key: 'docs', header: 'Documents', align: 'right', cell: (b) => <span className="tabular-nums">{num(b.document_count ?? 0)}</span> },
    { key: 'chunks', header: 'Chunks', align: 'right', hideBelow: 'sm', cell: (b) => <span className="tabular-nums">{num(b.chunk_count ?? 0)}</span> },
    { key: 'model', header: 'Embedding model', hideBelow: 'md', cell: (b) => <Badge tone="purple">{b.embed_model}</Badge> },
    { key: 'chunk', header: 'Chunk size', hideBelow: 'lg', align: 'right', cell: (b) => <span className="tabular-nums">{num(b.chunk_size)} chars</span> },
    { key: 'created', header: 'Created', hideBelow: 'lg', cell: (b) => dateTime(b.created_at) },
    {
      key: 'actions', header: <span className="sr-only">Actions</span>, align: 'right', cell: (b) => (
        <div onClick={(e) => e.stopPropagation()}>
          <ActionMenu actions={[
            { label: 'Open', icon: BookOpenIcon, onClick: () => setOpenId(b.id) },
            { label: 'Delete', icon: TrashIcon, danger: true, hidden: !canManage, onClick: () => setDeleting(b) },
          ]} />
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Knowledge base"
        description="Document collections for retrieval-augmented answers. Text is split into chunks and embedded on an online node."
        actions={canManage && <Button variant="primary" icon={PlusIcon} onClick={() => { create.reset(); setCreateOpen(true); }}>New knowledge base</Button>}
      />

      <Card>
        <Toolbar right={<span className="text-sm text-slate-500 tabular-nums dark:text-slate-400">{rows.length} bases</span>}>
          <SearchInput value={q} onChange={setQ} placeholder="Search knowledge bases…" />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(b) => b.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => void list.refetch()}
          onRowClick={(b) => setOpenId(b.id)}
          empty={
            <EmptyState
              icon={BookOpenIcon}
              title={list.data?.length ? 'No knowledge bases match' : 'No knowledge bases yet'}
              description={list.data?.length ? 'Try another search.' : 'Create a collection, then add documents to it.'}
              action={!list.data?.length && canManage ? <Button variant="primary" icon={PlusIcon} onClick={() => setCreateOpen(true)}>New knowledge base</Button> : undefined}
            />
          }
        />
      </Card>

      <FormModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="New knowledge base"
        submitLabel="Create"
        submitting={create.isPending}
        onSubmit={() => { if (!form.name.trim()) { toast.error('A name is required.'); return; } create.mutate(); }}
      >
        <Input label="Name" required maxLength={150} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={createErr.name} />
        <Input label="Description" maxLength={255} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="Embedding model" value={form.embed_model} onChange={(e) => setForm({ ...form, embed_model: e.target.value })} hint="Must be pulled on the embedding node." />
          <Input label="Chunk size (characters)" type="number" min={200} max={4000} step={100} value={form.chunk_size} onChange={(e) => setForm({ ...form, chunk_size: e.target.value })} />
        </div>
        {(servers.data?.length ?? 0) > 0 && (
          <Select
            label="Embedding node"
            value={form.server_id}
            onChange={(e) => setForm({ ...form, server_id: e.target.value })}
            placeholder="Any online node"
            options={(servers.data ?? []).filter((s) => s.is_enabled !== false).map((s) => ({ value: s.id, label: `${s.name}${s.status ? ` (${s.status})` : ''}` }))}
          />
        )}
      </FormModal>

      <BaseDetail id={openId} canManage={canManage} onClose={() => setOpenId(null)} onChanged={() => void refresh()} onDelete={(b) => setDeleting(b)} />

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        busy={remove.isPending}
        title="Delete knowledge base?"
        message={<>“{deleting?.name}” and all its documents and embeddings will be removed. Agents linked to it lose their knowledge.</>}
      />
    </>
  );
}
