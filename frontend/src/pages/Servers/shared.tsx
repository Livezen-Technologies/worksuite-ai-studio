import { cx, type BadgeTone } from '../../components/ui';

export type Kind = 'ollama' | 'openai' | 'anthropic' | 'gemini';
export type ControlAction = 'restart' | 'shutdown' | 'update';

export interface Server {
  id: number;
  name: string;
  description: string | null;
  kind: Kind | null;
  hostname: string | null;
  ip_address: string | null;
  api_url: string;
  port: number | string | null;
  has_token: boolean;
  gpu_model: string | null;
  cuda_version: string | null;
  cpu_cores: number | string | null;
  ram_gb: number | string | null;
  storage_gb: number | string | null;
  os: string | null;
  ollama_version: string | null;
  is_local: number | string;
  status: 'online' | 'offline' | 'degraded' | 'unknown';
  /** false = disabled: no new chat, gateway, knowledge or training work (health checks continue) */
  is_enabled?: boolean;
  disabled_at?: string | null;
  disabled_reason?: string | null;
  /** total CUDA cores (per-GPU figure × GPU count), derived from the GPU model; null when unknown or CPU-only */
  cuda_cores?: number | null;
  gpu_count?: number | string | null;
  health_score: number | string;
  last_heartbeat_at: string | null;
  created_at: string | null;
}

export interface HealthRow {
  id: number;
  status: string;
  health_score: number | string;
  latency_ms: number | null;
  models_total: number | null;
  models_running: number | null;
  error: string | null;
  checked_at: string;
}

export const KINDS: Record<Kind, { label: string; tone: BadgeTone; base?: string; keyHint?: string }> = {
  ollama: { label: 'Ollama node', tone: 'indigo' },
  openai: { label: 'OpenAI', tone: 'green', base: 'https://api.openai.com/v1', keyHint: 'sk-…' },
  anthropic: { label: 'Anthropic (Claude)', tone: 'amber', base: 'https://api.anthropic.com/v1', keyHint: 'sk-ant-…' },
  gemini: { label: 'Google Gemini', tone: 'blue', base: 'https://generativelanguage.googleapis.com/v1beta', keyHint: 'AIza…' },
};

export function HealthScore({ score }: { score: number | string | null | undefined }) {
  const n = Number(score ?? 0);
  return (
    <span className={cx('inline-flex items-center gap-1.5 text-sm font-medium tabular-nums',
      n >= 80 ? 'text-green-600 dark:text-green-400' : n >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400')}>
      <span className={cx('size-2 rounded-full', n >= 80 ? 'bg-green-500' : n >= 50 ? 'bg-amber-500' : 'bg-red-500')} />
      {n}
    </span>
  );
}
