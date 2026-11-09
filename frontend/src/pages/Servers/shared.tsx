import { cx, type BadgeTone } from '../../components/ui';

export type Kind = 'ollama' | 'vllm' | 'llamacpp' | 'openai_compatible' | 'openai' | 'anthropic' | 'gemini';
export type Deployment = 'local' | 'remote' | 'cloud' | 'docker';
export type Accelerator = 'cuda' | 'rocm' | 'cpu';
export type Availability = 'available' | 'busy' | 'degraded' | 'offline' | 'disabled' | 'unknown';
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
  deployment?: Deployment | null;
  accelerator?: Accelerator | null;
  rocm_version?: string | null;
  cloud_provider?: string | null;
  region?: string | null;
  container_image?: string | null;
  max_concurrency?: number | string | null;
  gpu_vram_gb?: number | string | null;
  /** latest utilization reported by the node agent */
  gpu_util_pct?: number | string | null;
  vram_used_gb?: number | string | null;
  cpu_util_pct?: number | string | null;
  ram_used_gb?: number | string | null;
  disk_used_gb?: number | string | null;
  gpu_temp_c?: number | string | null;
  metrics_at?: string | null;
  metrics_fresh?: boolean;
  availability?: Availability;
  /** node controls (restart / update / shut down), carried out by the node agent */
  pending_control?: string | null;
  control_result?: string | null;
  control_result_at?: string | null;
  agent_seen_at?: string | null;
  self_hosted?: boolean;
}

export interface MetricRow {
  gpu_util_pct: number | null; vram_used_gb: number | null; cpu_util_pct: number | null;
  ram_used_gb: number | null; disk_used_gb: number | null; gpu_temp_c: number | null; recorded_at: string;
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

/** cloud = a hosted commercial API (needs a key, no hardware); otherwise a self-hosted inference engine. */
export const KINDS: Record<Kind, { label: string; tone: BadgeTone; base?: string; keyHint?: string; cloud?: boolean; port?: number; urlHint?: string }> = {
  ollama: { label: 'Ollama node', tone: 'indigo', port: 11434, urlHint: 'http://192.168.1.20:11434' },
  vllm: { label: 'vLLM server', tone: 'purple', port: 8000, urlHint: 'http://192.168.1.20:8000/v1' },
  llamacpp: { label: 'llama.cpp server', tone: 'purple', port: 8080, urlHint: 'http://192.168.1.20:8080/v1' },
  openai_compatible: { label: 'OpenAI-compatible server', tone: 'purple', port: 8000, urlHint: 'http://192.168.1.20:8000/v1' },
  openai: { label: 'OpenAI', tone: 'green', cloud: true, base: 'https://api.openai.com/v1', keyHint: 'sk-…' },
  anthropic: { label: 'Anthropic (Claude)', tone: 'amber', cloud: true, base: 'https://api.anthropic.com/v1', keyHint: 'sk-ant-…' },
  gemini: { label: 'Google Gemini', tone: 'blue', cloud: true, base: 'https://generativelanguage.googleapis.com/v1beta', keyHint: 'AIza…' },
};

export const isCloud = (k: Kind | null | undefined) => !!KINDS[k ?? 'ollama']?.cloud;

export const DEPLOYMENTS: Record<Deployment, string> = { local: 'Local server', remote: 'Remote dedicated server', cloud: 'Cloud GPU instance', docker: 'Docker container' };
export const ACCELERATORS: Record<Accelerator, string> = { cuda: 'NVIDIA CUDA', rocm: 'AMD ROCm', cpu: 'CPU only' };
export const AVAILABILITY: Record<Availability, { label: string; tone: BadgeTone }> = {
  available: { label: 'Available', tone: 'green' }, busy: { label: 'Busy', tone: 'amber' }, degraded: { label: 'Degraded', tone: 'amber' },
  offline: { label: 'Offline', tone: 'red' }, disabled: { label: 'Disabled', tone: 'gray' }, unknown: { label: 'Unknown', tone: 'gray' },
};

/** A thin utilization bar with a label, e.g. GPU 72%. */
export function UtilBar({ label, pct, detail }: { label: string; pct: number | null | undefined; detail?: string }) {
  if (pct == null || Number.isNaN(pct)) return null;
  const p = Math.max(0, Math.min(100, pct));
  return (
    <div className="min-w-24">
      <div className="flex justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400"><span>{label}</span><span className="tabular-nums">{detail ?? `${Math.round(p)}%`}</span></div>
      <div className="mt-0.5 h-1.5 rounded-full bg-slate-200 dark:bg-white/10">
        <div className={cx('h-1.5 rounded-full', p >= 90 ? 'bg-red-500' : p >= 70 ? 'bg-amber-500' : 'bg-green-500')} style={{ width: `${p}%` }} />
      </div>
    </div>
  );
}

const n = (v: unknown) => (v == null || v === '' ? null : Number(v));
/** GPU / VRAM / CPU / RAM bars from the node's latest report; nothing when the agent has not reported recently. */
export function Utilization({ s, compact = false }: { s: Server; compact?: boolean }) {
  if (!s.metrics_fresh) return compact ? <span className="text-xs text-slate-400 dark:text-slate-500">No agent data</span> : null;
  const vram = n(s.gpu_vram_gb) && n(s.vram_used_gb) != null ? (n(s.vram_used_gb)! / n(s.gpu_vram_gb)!) * 100 : null;
  const ram = n(s.ram_gb) && n(s.ram_used_gb) != null ? (n(s.ram_used_gb)! / n(s.ram_gb)!) * 100 : null;
  return (
    <div className={cx('grid gap-1.5', compact ? 'w-32' : 'grid-cols-2 gap-3 sm:grid-cols-4')}>
      <UtilBar label="GPU" pct={n(s.gpu_util_pct)} detail={n(s.gpu_temp_c) != null && !compact ? `${Math.round(n(s.gpu_util_pct) ?? 0)}% · ${Math.round(n(s.gpu_temp_c)!)}°C` : undefined} />
      <UtilBar label="VRAM" pct={vram} detail={`${n(s.vram_used_gb)}/${n(s.gpu_vram_gb)} GB`} />
      <UtilBar label="CPU" pct={n(s.cpu_util_pct)} />
      <UtilBar label="RAM" pct={ram} detail={compact ? undefined : `${n(s.ram_used_gb)}/${n(s.ram_gb)} GB`} />
    </div>
  );
}

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
