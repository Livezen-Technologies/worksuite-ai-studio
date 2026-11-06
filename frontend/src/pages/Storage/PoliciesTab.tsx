import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, FormModal, Input, Segmented, Select, useToast } from '../../components/ui';
import { del, get, post, put } from '../../lib/api';
import { STRATEGY, type Policy } from './shared';

export function PoliciesTab({ manage }: { manage: boolean }) {
  const toast = useToast();
  const q = useQuery({ queryKey: ['storage-policies'], queryFn: () => get<Policy[]>('/storage/policies') });
  const [editing, setEditing] = useState<Policy | 'new' | null>(null);
  const remove = useMutation({ mutationFn: (p: Policy) => del(`/storage/policies/${p.id}`), onSuccess: () => { toast.success('Policy deleted'); q.refetch(); }, onError: (e) => toast.error(e) });
  return (
    <Card>
      <CardHeader title="Placement policies" description="How many copies a file gets and how nodes are picked. An app uses its own policy if it has one, otherwise the default."
        actions={manage && <Button size="sm" icon={PlusIcon} onClick={() => setEditing('new')}>Add policy</Button>} />
      <ul className="divide-y divide-slate-200 dark:divide-white/10">
        {(q.data ?? []).map((p) => (
          <li key={p.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-slate-900 dark:text-white">{p.name} {Number(p.is_default) === 1 && <Badge tone="blue">default</Badge>} {p.app && <Badge>app: {p.app}</Badge>}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{p.replicas} {Number(p.replicas) === 1 ? 'copy' : 'copies'}{Number(p.min_regions ?? 1) > 1 ? ` in ${p.min_regions}+ regions` : ''} · {STRATEGY[p.strategy]}{p.node_tags ? ` · nodes tagged ${p.node_tags}` : ''} · {p.files} files</p>
            </div>
            {manage && (
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" icon={PencilSquareIcon} onClick={() => setEditing(p)}>Edit</Button>
                {Number(p.is_default) !== 1 && <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => remove.mutate(p)}>Delete</Button>}
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && <PolicyForm policy={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); q.refetch(); }} />}
    </Card>
  );
}

function PolicyForm({ policy, onClose, onSaved }: { policy: Policy | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [name, setName] = useState(policy?.name ?? '');
  const [replicas, setReplicas] = useState(String(policy?.replicas ?? 2));
  const [regions, setRegions] = useState(String(policy?.min_regions ?? 1));
  const [strategy, setStrategy] = useState(policy?.strategy ?? 'capacity');
  const [tags, setTags] = useState(policy?.node_tags ?? '');
  const [app, setApp] = useState(policy?.app ?? '');
  const [isDefault, setIsDefault] = useState(Number(policy?.is_default ?? 0) === 1);
  const save = useMutation({
    mutationFn: () => { const body = { name, replicas: Number(replicas), min_regions: Number(regions), strategy, node_tags: tags, app, is_default: isDefault }; return policy ? put(`/storage/policies/${policy.id}`, body) : post('/storage/policies', body); },
    onSuccess: () => { toast.success('Policy saved'); onSaved(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={policy ? 'Edit policy' : 'Add policy'} onSubmit={() => save.mutate()} submitting={save.isPending}>
      <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Copies" type="number" min={1} max={5} value={replicas} onChange={(e) => setReplicas(e.target.value)} hint="Capped by the number of active nodes" />
        <Select label="Choose nodes by" value={strategy} onChange={(e) => setStrategy(e.target.value)}
          options={Object.entries(STRATEGY).map(([value, label]) => ({ value, label }))} />
      </div>
      <Select label="Off-site copies" value={regions} onChange={(e) => setRegions(e.target.value)}
        hint="Disaster recovery: copies must sit in this many different regions (the Region / site of each node). Extra copies are added when needed."
        options={[{ value: '1', label: 'Any region' }, { value: '2', label: 'At least 2 regions' }, { value: '3', label: 'At least 3 regions' }]} />
      <Input label="Only nodes tagged" value={tags} onChange={(e) => setTags(e.target.value)} hint="Comma-separated; a node needs every tag. Leave empty for any node." />
      <Input label="Use for app" value={app} onChange={(e) => setApp(e.target.value)} hint="e.g. datasets or recordings. Leave empty for a general policy." />
      <Segmented label="Default" value={isDefault ? 'yes' : 'no'} onChange={(v) => setIsDefault(v === 'yes')} options={[{ value: 'no', label: 'Not default' }, { value: 'yes', label: 'Default policy' }]} />
    </FormModal>
  );
}
