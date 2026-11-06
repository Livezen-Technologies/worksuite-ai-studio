import type { LineageJob } from '../SinLama/TrainingLineage';

export type Status = 'draft' | 'kit_ready' | 'preparing' | 'queued' | 'running' | 'completed' | 'failed' | 'cancelled' | 'imported';
export interface Hyper {
  epochs: number; learning_rate: number; lora_rank: number; lora_alpha: number; lora_dropout: number;
  batch_size: number; grad_accum: number; max_seq_len: number; warmup_steps: number; seed: number; quant_export: string;
}
export interface Job extends LineageJob {
  id: number; name: string; dataset_id: number | null; dataset_name: string | null; dataset_items: number | null;
  base_model: string; method: 'lora' | 'qlora' | 'finetune'; hyperparams: string | Partial<Hyper> | null; status: Status;
  notes: string | null; logs: string | null; created_at: string; updated_at: string | null;
  progress_pct?: string | number | null; eta_seconds?: number | string | null; current_step?: number | string | null;
  total_steps?: number | string | null; phase?: string | null; last_report_at?: string | null;
  /** set for jobs a training pipeline created (they run through a node's training agent) */
  pipeline_id?: number | null; recipe?: string | null; error?: string | null; trigger_reason?: string | null; attempt?: number | string | null;
}
export const ACTIVE: Status[] = ['preparing', 'queued', 'running'];
export interface ListResponse { jobs: Job[]; base_models: string[]; defaults: Hyper }
export interface Dataset { id: number; name: string; item_count: number | string }

export const STATUSES: { value: Status; label: string }[] = [
  { value: 'draft', label: 'Draft' },
  { value: 'kit_ready', label: 'Kit ready' },
  { value: 'preparing', label: 'Preparing' },
  { value: 'queued', label: 'Queued' },
  { value: 'running', label: 'Running' },
  { value: 'completed', label: 'Completed' },
  { value: 'failed', label: 'Failed' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'imported', label: 'Imported' },
];
export const statusLabel = (s: Status) => STATUSES.find((x) => x.value === s)?.label ?? s;
export const QUANTS = ['q4_k_m', 'q5_k_m', 'q8_0', 'f16'];
export const HAS_KIT: Status[] = ['kit_ready', 'running', 'completed', 'failed', 'imported'];

export function parseHyper(h: Job['hyperparams']): Partial<Hyper> {
  if (!h) return {};
  if (typeof h === 'object') return h;
  try { return JSON.parse(h) ?? {}; } catch { return {}; }
}
