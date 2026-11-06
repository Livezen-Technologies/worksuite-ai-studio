export interface Server { id: number; name: string; kind?: string; status?: string; is_enabled?: boolean }
export interface NodeModel { id: number; name: string; server_id: number }
export interface Preset { key: string; label: string; name: string; system: string; parameters: { temperature?: number; num_ctx?: number } }
export interface Blob { digest: string; filename: string; size: number }
export interface Upload { file: File; progress: number; blob?: Blob; error?: string }
export type Mode = 'customize' | 'import';

export const NAME_RE = /^[a-z0-9][a-z0-9._/-]*(:[a-z0-9._-]+)?$/i;
export const QUANTS = ['q4_K_M', 'q4_K_S', 'q8_0'];
