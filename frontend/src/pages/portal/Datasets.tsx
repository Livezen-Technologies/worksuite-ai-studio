import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowUpTrayIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, ConfirmDialog, DataTable, FormModal, Input, PageHeader, Select, StatusBadge, Textarea,
  useDisclosure, useToast, type Column,
} from '../../components/ui';
import { del, get } from '../../lib/api';
import { ago, num } from '../../lib/format';
import { uploadWithProgress } from '../SinLama/upload';

interface Dataset {
  id: number; name: string; language: string; description: string | null; item_count: number | string;
  visibility: 'private' | 'shared'; rights_status?: string; mine: boolean; created_at: string;
}
const ACCEPT = '.json,.jsonl,.csv';

export default function PortalDatasets() {
  const toast = useToast();
  const qc = useQueryClient();
  const create = useDisclosure();
  const [toDelete, setToDelete] = useState<Dataset | null>(null);
  const [uploading, setUploading] = useState<{ id: number; pct: number } | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const target = useRef<number | null>(null);

  const list = useQuery({ queryKey: ['portal', 'datasets'], queryFn: () => get<Dataset[]>('/portal/datasets') });
  const refresh = () => qc.invalidateQueries({ queryKey: ['portal'] });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/portal/datasets/${id}`),
    onSuccess: () => { toast.success('Dataset deleted'); setToDelete(null); refresh(); },
    onError: (e) => toast.error(e),
  });

  async function upload(id: number, file: File) {
    const form = new FormData();
    form.append('file', file);
    setUploading({ id, pct: 0 });
    try {
      const { message } = await uploadWithProgress(`/portal/datasets/${id}/upload`, form, (pct) => setUploading({ id, pct }));
      toast.success('Rows imported', message);
      refresh();
    } catch (e) {
      toast.error(e);
    } finally {
      setUploading(null);
    }
  }

  const cols: Column<Dataset>[] = [
    { key: 'name', header: 'Dataset', cell: (d) => <div><div className="font-medium">{d.name}</div><div className="text-xs text-slate-500">{d.description ?? d.language}</div></div> },
    { key: 'rows', header: 'Rows', cell: (d) => num(Number(d.item_count)), align: 'right' },
    { key: 'vis', header: 'Visibility', cell: (d) => <Badge tone={d.visibility === 'shared' ? 'blue' : 'gray'}>{d.visibility === 'shared' ? 'Shared' : 'Private'}</Badge>, hideBelow: 'sm' },
    { key: 'owner', header: 'Owner', cell: (d) => d.mine ? 'You' : 'Shared with you', hideBelow: 'md' },
    { key: 'rights', header: 'Rights', cell: (d) => <StatusBadge status={d.rights_status ?? 'quarantined'} />, hideBelow: 'md' },
    { key: 'at', header: 'Created', cell: (d) => ago(d.created_at), hideBelow: 'lg' },
    { key: 'act', header: '', align: 'right', cell: (d) => d.mine && (
      <ActionMenu actions={[
        { label: uploading?.id === d.id ? `Uploading ${Math.round(uploading.pct * 100)}%` : 'Upload rows', icon: ArrowUpTrayIcon, disabled: uploading !== null,
          onClick: () => { target.current = d.id; fileRef.current?.click(); } },
        { label: 'Delete', icon: TrashIcon, danger: true, onClick: () => setToDelete(d) },
      ]} />
    ) },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="My datasets" description="Upload instruction data (JSONL, JSON or CSV) for your training jobs. An administrator approves rights and freezes splits before a kit can be built."
        actions={<Button variant="primary" icon={PlusIcon} onClick={create.onOpen}>New dataset</Button>} />
      <Card>
        <DataTable columns={cols} rows={list.data} rowKey={(d) => d.id} loading={list.isLoading} error={list.error} onRetry={list.refetch} empty="No datasets yet." />
      </Card>
      <input ref={fileRef} type="file" accept={ACCEPT} hidden onChange={(e) => { const f = e.target.files?.[0]; if (f && target.current) upload(target.current, f); e.target.value = ''; }} />
      <CreateDataset open={create.open} onClose={create.onClose} onCreated={refresh} />
      <ConfirmDialog open={toDelete !== null} onClose={() => setToDelete(null)} onConfirm={() => toDelete && remove.mutate(toDelete.id)}
        busy={remove.isPending} title="Delete dataset?" message={`"${toDelete?.name}" and all its rows will be removed.`} />
    </div>
  );
}

function CreateDataset({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const toast = useToast();
  const [name, setName] = useState('');
  const [language, setLanguage] = useState('si');
  const [description, setDescription] = useState('');
  const [visibility, setVisibility] = useState('private');
  const [file, setFile] = useState<File | null>(null);
  const [progress, setProgress] = useState<number | null>(null);

  async function submit() {
    const form = new FormData();
    form.append('name', name);
    form.append('language', language);
    form.append('description', description);
    form.append('visibility', visibility);
    if (file) form.append('file', file);
    setProgress(0);
    try {
      const { message } = await uploadWithProgress('/portal/datasets', form, setProgress);
      toast.success('Dataset created', message);
      setName(''); setDescription(''); setFile(null);
      onCreated(); onClose();
    } catch (e) {
      toast.error(e);
    } finally {
      setProgress(null);
    }
  }

  return (
    <FormModal open={open} onClose={onClose} title="New dataset" onSubmit={submit} submitLabel="Create" submitting={progress !== null}>
      <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} autoFocus />
      <Select label="Language" value={language} onChange={(e) => setLanguage(e.target.value)} options={[{ value: 'si', label: 'Sinhala' }, { value: 'en', label: 'English' }, { value: 'si-en', label: 'Sinhala + English' }]} />
      <Select label="Visibility" value={visibility} onChange={(e) => setVisibility(e.target.value)}
        options={[{ value: 'private', label: 'Private — only you' }, { value: 'shared', label: 'Shared — other portal users can train on it' }]} />
      <Textarea label="Description" value={description} onChange={(e) => setDescription(e.target.value)} rows={2} />
      <Input label="Rows file (optional)" type="file" accept={ACCEPT} onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        hint={progress !== null ? `Uploading ${Math.round(progress * 100)}%` : 'JSONL / JSON / CSV with instruction, input, output.'} />
    </FormModal>
  );
}
