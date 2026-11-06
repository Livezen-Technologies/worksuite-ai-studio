import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardHeader, FormModal, Input, Select, Toggle, useToast } from '../../components/ui';
import { del, post, put } from '../../lib/api';
import { METRICS, type Node, type Rule } from './shared';

export function Rules({ rules, nodes, manage, onChange }: { rules: Rule[]; nodes: Node[]; manage: boolean; onChange: () => void }) {
  const toast = useToast();
  const [editing, setEditing] = useState<Rule | 'new' | null>(null);
  const toggle = useMutation({ mutationFn: (r: Rule) => put(`/storage/rules/${r.id}`, { ...r, enabled: Number(r.enabled) !== 1 }), onSuccess: onChange, onError: (e) => toast.error(e) });
  const remove = useMutation({ mutationFn: (r: Rule) => del(`/storage/rules/${r.id}`), onSuccess: () => { toast.success('Rule deleted'); onChange(); }, onError: (e) => toast.error(e) });
  return (
    <Card>
      <CardHeader title="Alert rules" description="Checked after every health check. Errors also go to the configured Slack / email / webhook channels."
        actions={manage && <Button size="sm" icon={PlusIcon} onClick={() => setEditing('new')}>Add rule</Button>} />
      <ul className="divide-y divide-slate-200 dark:divide-white/10">
        {rules.map((r) => (
          <li key={r.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-slate-900 dark:text-white">{r.name} <Badge tone={r.level === 'error' ? 'red' : 'amber'}>{r.level}</Badge></p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {r.metric === 'down' ? 'When a node fails its check' : `${METRICS[r.metric]} ${r.op === 'gt' ? 'above' : 'below'} ${Number(r.threshold)}`} · {r.node_id ? nodes.find((n) => n.id === r.node_id)?.name ?? `node #${r.node_id}` : 'every node'}
              </p>
            </div>
            {manage && (
              <div className="flex items-center gap-2">
                <Toggle checked={Number(r.enabled) === 1} onChange={() => toggle.mutate(r)} label="On" />
                <Button size="sm" variant="secondary" icon={PencilSquareIcon} onClick={() => setEditing(r)}>Edit</Button>
                <Button size="sm" variant="secondary" icon={TrashIcon} onClick={() => remove.mutate(r)}>Delete</Button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && <RuleForm rule={editing === 'new' ? null : editing} nodes={nodes} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); onChange(); }} />}
    </Card>
  );
}

export function RuleForm({ rule, nodes, onClose, onSaved }: { rule: Rule | null; nodes: Node[]; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [name, setName] = useState(rule?.name ?? '');
  const [metric, setMetric] = useState(rule?.metric ?? 'used_pct');
  const [op, setOp] = useState<'gt' | 'lt'>(rule?.op ?? 'gt');
  const [threshold, setThreshold] = useState(String(rule?.threshold ?? 85));
  const [level, setLevel] = useState<'warning' | 'error'>(rule?.level ?? 'warning');
  const [nodeId, setNodeId] = useState(String(rule?.node_id ?? ''));
  const save = useMutation({
    mutationFn: () => {
      const body = { name, metric, op, threshold: Number(threshold), level, node_id: Number(nodeId) || null, enabled: rule ? Number(rule.enabled) === 1 : true };
      return rule ? put(`/storage/rules/${rule.id}`, body) : post('/storage/rules', body);
    },
    onSuccess: () => { toast.success('Rule saved'); onSaved(); }, onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} title={rule ? 'Edit alert rule' : 'Add an alert rule'} submitLabel="Save rule" submitting={save.isPending} onSubmit={() => save.mutate()}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Disk 85% full" wrapperClassName="sm:col-span-2" />
        <Select label="When" value={metric} onChange={(e) => setMetric(e.target.value)} options={Object.entries(METRICS).map(([v, l]) => ({ value: v, label: l }))} wrapperClassName="sm:col-span-2" />
        {metric !== 'down' && <>
          <Select label="Is" value={op} onChange={(e) => setOp(e.target.value as 'gt' | 'lt')} options={[{ value: 'gt', label: 'above' }, { value: 'lt', label: 'below' }]} />
          <Input label="Threshold" type="number" step="any" value={threshold} onChange={(e) => setThreshold(e.target.value)} />
        </>}
        <Select label="Level" value={level} onChange={(e) => setLevel(e.target.value as 'warning' | 'error')} options={[{ value: 'warning', label: 'Warning' }, { value: 'error', label: 'Error (also sent to channels)' }]} />
        <Select label="Nodes" value={nodeId} onChange={(e) => setNodeId(e.target.value)} options={[{ value: '', label: 'Every node' }, ...nodes.map((n) => ({ value: String(n.id), label: n.name }))]} />
      </div>
    </FormModal>
  );
}
