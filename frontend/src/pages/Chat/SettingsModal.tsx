import { Badge, Button, Modal, Select, Textarea } from '../../components/ui';
import { ago } from '../../lib/format';
import type { Agent, Session } from './shared';

/** Conversation settings: start from an agent, system prompt, and the current conversation's facts. */
export function SettingsModal({ open, onClose, activeAgents, onPickAgent, systemPrompt, setSystemPrompt, locked, current, model }: {
  open: boolean;
  onClose: () => void;
  activeAgents: Agent[];
  onPickAgent: (a: Agent) => void;
  systemPrompt: string;
  setSystemPrompt: (v: string) => void;
  locked: boolean;
  current: Session | null;
  model: string;
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Conversation settings"
      description="Temperature, context length and keep-alive come from the model's own configuration on the Models page."
      footer={<Button variant="primary" onClick={onClose}>Done</Button>}
    >
      <div className="space-y-5">
        {activeAgents.length > 0 && (
          <Select
            label="Start from an agent"
            hint="Starts a new conversation with the agent's model and system prompt."
            value=""
            placeholder="Choose an agent…"
            options={activeAgents.map((a) => ({ value: a.id, label: `${a.avatar ?? ''} ${a.name}${a.model ? ` · ${a.model}` : ''}`.trim() }))}
            onChange={(e) => { const a = activeAgents.find((x) => x.id === Number(e.target.value)); if (a) onPickAgent(a); }}
          />
        )}
        <Textarea
          label="System prompt"
          rows={6}
          value={systemPrompt}
          disabled={locked}
          onChange={(e) => setSystemPrompt(e.target.value)}
          placeholder="You are a helpful assistant…"
          hint={locked ? 'Fixed for this conversation. Start a new chat to use a different system prompt.' : "Used for the new conversation. Leave empty for the model's default."}
        />
        {current && (
          <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <Badge tone="blue">{current.model ?? model}</Badge>
            <span>{Number(current.message_count)} messages · updated {ago(current.updated_at)}</span>
          </div>
        )}
      </div>
    </Modal>
  );
}
