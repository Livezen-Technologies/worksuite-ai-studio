import { Badge, Modal } from '../../components/ui';
import type { Prompt } from './shared';

/** Picker for inserting a saved prompt into the draft. */
export function PromptsModal({ open, onClose, prompts, onPick }: {
  open: boolean;
  onClose: () => void;
  prompts: Prompt[];
  onPick: (p: Prompt) => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="Insert a saved prompt" size="lg">
      <ul className="divide-y divide-slate-100 dark:divide-white/5">
        {prompts.map((p) => (
          <li key={p.id}>
            <button
              type="button"
              className="w-full rounded-md px-2 py-3 text-left hover:bg-slate-50 dark:hover:bg-white/5"
              onClick={() => onPick(p)}
            >
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium text-slate-900 dark:text-white">{p.name}</span>
                <Badge tone="blue">{p.category}</Badge>
                {p.variables.length > 0 && <Badge tone="purple">{p.variables.length} variables</Badge>}
              </div>
              <p className="mt-1 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{p.content}</p>
            </button>
          </li>
        ))}
      </ul>
    </Modal>
  );
}
