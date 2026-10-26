import { invoke } from '@tauri-apps/api/core';

export interface Settings {
  server: string; proxy_port: number; ollama_url: string; sample_seconds: number; start_minimized: boolean; close_to_tray: boolean;
}
export interface Enrolment { server: string; server_id: string; name: string; tunnel_port: number; ssh_target: string; ssh_port: number }
export interface TunnelStatus {
  state: 'stopped' | 'connecting' | 'connected' | 'retrying'; since: number; reconnects: number; last_error: string | null; connected_secs: number; started_at: number;
}
export interface Gpu { name: string; util: number; mem_used_mb: number; mem_total_mb: number; temp_c: number; power_w: number }
export interface Machine {
  hostname: string; os: string; cpu_name: string; cpu_cores: number; cpu: number; ram_used_gb: number; ram_total_gb: number;
  disk_free_gb: number; disk_total_gb: number; gpus: Gpu[]; cuda: string | null;
}
export interface OllamaModel { name: string; size: number; size_vram: number; expires_at?: string | null; details?: { parameter_size?: string; quantization_level?: string; family?: string } | null }
export interface Ollama { up: boolean; version: string | null; models: OllamaModel[]; running: OllamaModel[] }
export interface Overview {
  enrolment: Enrolment | null; settings: Settings; tunnel: TunnelStatus; machine: Machine; ollama: Ollama; requests: number; errors: number; tokens: number;
}
export interface Sample { t: number; cpu: number; ram: number; gpu: number; vram: number; connected: boolean; requests: number; tokens: number }
export interface ModelTotals { requests: number; prompt_tokens: number; output_tokens: number; ms: number }
export interface RequestRecord { at: number; method: string; path: string; model: string | null; ms: number; prompt_tokens: number; output_tokens: number; ok: boolean }
export interface Analytics { samples: Sample[]; by_model: Record<string, ModelTotals>; recent: RequestRecord[] }

export const api = {
  overview: () => invoke<Overview>('overview'),
  analytics: (rangeSecs: number) => invoke<Analytics>('analytics', { rangeSecs }),
  enrol: (server: string, token: string, name: string) => invoke<Enrolment>('enrol', { server, token, name }),
  disconnect: () => invoke<void>('disconnect'),
  setTunnel: (on: boolean) => invoke<void>('set_tunnel', { on }),
  saveSettings: (settings: Settings) => invoke<void>('save_settings', { settings }),
  readLog: () => invoke<string>('read_log'),
};

export const gb = (n: number) => `${n.toFixed(n < 10 ? 1 : 0)} GB`;
export const pct = (n: number) => `${Math.round(n)}%`;
export const compact = (n: number) => Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(n);
export const bytes = (n: number) => (n >= 1e9 ? `${(n / 1e9).toFixed(1)} GB` : `${Math.round(n / 1e6)} MB`);
export function duration(secs: number) {
  const d = Math.floor(secs / 86400), h = Math.floor((secs % 86400) / 3600), m = Math.floor((secs % 3600) / 60);
  return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
}
export const errorText = (e: unknown) => (typeof e === 'string' ? e : e instanceof Error ? e.message : JSON.stringify(e));
