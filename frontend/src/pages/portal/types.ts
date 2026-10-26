/** Shared types for the user portal (/portal) and compute admin (/compute). */
export type NodeStatus = 'pending' | 'assigned' | 'running' | 'completed' | 'failed' | 'cancelled';
export type PlacementStatus = 'unplaced' | 'pending' | 'running' | 'completed' | 'failed' | 'partial' | 'cancelled';

export interface Allocation {
  id: number; user_id: number; server_id: number;
  max_cpu_cores: number; max_gpus: number; max_concurrent_jobs: number;
  expires_at: string | null; notes: string | null; expired: boolean;
  server_name: string; server_status: string; server_cpu_cores: number | null; server_gpu_count: number;
  gpu_model: string | null; gpu_vram_gb: string | null; ram_gb: string | null; allow_training: number | string;
  user_name: string | null; user_email: string | null;
  used_cpu_cores: number; used_gpus: number; active_jobs: number; free_cpu_cores: number; free_gpus: number;
}

export interface JobNode {
  id: number; training_job_id: number; server_id: number; server_name: string | null; server_status: string | null;
  gpu_model: string | null; cpu_cores: number; gpus: number; status: NodeStatus; progress: number;
  message: string | null; override: number | string; started_at: string | null; finished_at: string | null;
}

export interface NodeRequest { server_id: number; cpu_cores: number; gpus: number }

export interface TrainingNode {
  id: number; name: string; status: string; cpu_cores: number | null; ram_gb: string | null;
  gpu_model: string | null; gpu_count: number; gpu_vram_gb: string | null; allow_training: number | string;
  used_cpu_cores: number; used_gpus: number; active_jobs: number;
}

export const PLACEMENT_LABEL: Record<PlacementStatus, string> = {
  unplaced: 'Not placed', pending: 'Waiting', running: 'Running', completed: 'Completed',
  failed: 'Failed', partial: 'Partly failed', cancelled: 'Cancelled',
};
export const NODE_STATUS_LABEL: Record<NodeStatus, string> = {
  pending: 'Pending', assigned: 'Assigned', running: 'Running', completed: 'Completed', failed: 'Failed', cancelled: 'Cancelled',
};
