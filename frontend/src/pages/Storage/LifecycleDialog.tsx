import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { FormModal, Input, useToast } from '../../components/ui';
import { post } from '../../lib/api';
import { ACTION, type Node } from './shared';

export function LifecycleDialog({ node, to, onClose, onDone }: { node: Node; to: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const toast = useToast();
  const go = useMutation({ mutationFn: () => post(`/storage/nodes/${node.id}/status`, { to, reason }), onSuccess: () => { toast.success(`${node.name}: ${to}`); onDone(); }, onError: (e) => toast.error(e) });
  const text: Record<string, string> = {
    active: 'The node starts receiving new data.',
    draining: 'The node stops receiving new data; files on it stay readable while they are moved elsewhere.',
    retired: 'The node is no longer used or checked. Files on it are not deleted.',
    testing: 'The node goes back to testing; it is checked but receives no data.',
  };
  return (
    <FormModal open onClose={onClose} title={`${ACTION[to]} ${node.name}`} description={text[to]} submitLabel={ACTION[to]} submitting={go.isPending} onSubmit={() => go.mutate()}>
      <Input label="Reason (optional)" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Moving to a larger disk" />
    </FormModal>
  );
}
