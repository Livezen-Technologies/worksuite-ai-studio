import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { DocumentDuplicateIcon } from '@heroicons/react/24/outline';
import { Button, Card, CardHeader, Input, Select, useToast } from '../../components/ui';
import { ApiError, get, post } from '../../lib/api';
import { NAME_RE, type NodeModel } from './shared';

export default function CopyCard({ nodes, defaultServer, canManage }: { nodes: { value: number; label: string }[]; defaultServer: string; canManage: boolean }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [serverId, setServerId] = useState(defaultServer);
  const [source, setSource] = useState('');
  const [destination, setDestination] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => { if (!serverId && defaultServer) setServerId(defaultServer); }, [defaultServer, serverId]);
  const models = useQuery({
    queryKey: ['models', { server_id: serverId }],
    queryFn: () => get<NodeModel[]>('/models', { server_id: serverId }),
    enabled: Boolean(serverId),
  });
  const copy = useMutation({
    mutationFn: () => post('/studio/copy', { server_id: Number(serverId), source, destination: destination.trim() }),
    onSuccess: () => {
      toast.success(`Cloned ${source} → ${destination.trim()}`);
      setDestination('');
      qc.invalidateQueries({ queryKey: ['models'] });
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });
  function submit() {
    const e: Record<string, string> = {};
    if (!source) e.source = 'Pick the model to clone.';
    if (!destination.trim()) e.destination = 'Name the copy.';
    else if (!NAME_RE.test(destination.trim())) e.destination = 'Letters, numbers, ".", "_", "-" and an optional :tag only.';
    setErrors(e);
    if (!Object.keys(e).length) copy.mutate();
  }
  return (
    <Card>
      <CardHeader title="Clone a model" description="Copy an existing model under a new name on the same node." />
      <div className="space-y-4 p-4 sm:p-6">
        <Select label="Node" value={serverId} onChange={(e) => { setServerId(e.target.value); setSource(''); }} options={nodes} placeholder="Select a node" disabled={!canManage} />
        <Select
          label="Source model"
          value={source}
          onChange={(e) => setSource(e.target.value)}
          options={(models.data ?? []).map((m) => ({ value: m.name, label: m.name }))}
          placeholder={models.isLoading ? 'Loading…' : 'Select a model'}
          error={errors.source}
          disabled={!canManage || !serverId}
        />
        <Input label="New name" value={destination} onChange={(e) => setDestination(e.target.value)} placeholder="sinlama-backup" error={errors.destination} disabled={!canManage} />
        <div className="flex justify-end">
          <Button variant="secondary" icon={DocumentDuplicateIcon} loading={copy.isPending} disabled={!canManage} onClick={submit}>Clone</Button>
        </div>
      </div>
    </Card>
  );
}
