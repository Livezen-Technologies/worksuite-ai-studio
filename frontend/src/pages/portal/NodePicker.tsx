import { Badge, Checkbox, Input, StatusBadge } from '../../components/ui';
import type { Allocation, NodeRequest } from './types';

/**
 * Choose nodes and how many CPU cores / GPUs to take on each, capped by what is free in the user's allocation.
 * `extraFree` adds back capacity this job already holds (when editing an existing placement).
 */
export default function NodePicker({ allocations, value, onChange, extraFree = {} }: {
  allocations: Allocation[];
  value: NodeRequest[];
  onChange: (v: NodeRequest[]) => void;
  extraFree?: Record<number, { cpu: number; gpus: number }>;
}) {
  if (!allocations.length) {
    return <p className="text-sm text-slate-500">You have no node allocations yet. Ask an administrator to allocate a node to you.</p>;
  }
  const set = (sid: number, patch: Partial<NodeRequest> | null) => {
    const rest = value.filter((v) => v.server_id !== sid);
    if (patch === null) return onChange(rest);
    const cur = value.find((v) => v.server_id === sid) ?? { server_id: sid, cpu_cores: 0, gpus: 0 };
    onChange([...rest, { ...cur, ...patch }].sort((a, b) => a.server_id - b.server_id));
  };
  return (
    <ul className="divide-y divide-slate-200 rounded-lg ring-1 ring-slate-200 dark:divide-white/10 dark:ring-white/10">
      {allocations.map((a) => {
        const sel = value.find((v) => v.server_id === a.server_id);
        const extra = extraFree[a.server_id] ?? { cpu: 0, gpus: 0 };
        const freeCpu = a.free_cpu_cores + extra.cpu;
        const freeGpu = a.free_gpus + extra.gpus;
        const jobsFull = a.active_jobs - (extra.cpu || extra.gpus ? 1 : 0) >= a.max_concurrent_jobs;
        const full = (freeCpu < 1 && freeGpu < 1) || jobsFull;
        return (
          <li key={a.server_id} className="p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Checkbox
                checked={Boolean(sel)}
                disabled={!sel && full}
                onChange={(on) => set(a.server_id, on ? { cpu_cores: Math.min(freeCpu, 1), gpus: Math.min(freeGpu, 1) } : null)}
                label={<span className="font-medium">{a.server_name}</span>}
              />
              <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <StatusBadge status={a.server_status} />
                {a.gpu_model && <Badge tone="purple">{a.gpu_model}</Badge>}
                <span>{freeCpu}/{a.max_cpu_cores} CPU free</span>
                <span>{freeGpu}/{a.max_gpus} GPU free</span>
                {jobsFull && <Badge tone="amber">Job limit reached</Badge>}
              </div>
            </div>
            {sel && (
              <div className="mt-3 grid grid-cols-2 gap-3 sm:max-w-sm">
                <Input label="CPU cores" type="number" min={0} max={freeCpu} value={sel.cpu_cores}
                  onChange={(e) => set(a.server_id, { cpu_cores: Math.max(0, Math.min(freeCpu, Number(e.target.value) || 0)) })} />
                <Input label="GPUs" type="number" min={0} max={freeGpu} value={sel.gpus} disabled={a.max_gpus === 0}
                  onChange={(e) => set(a.server_id, { gpus: Math.max(0, Math.min(freeGpu, Number(e.target.value) || 0)) })} />
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
