import { CloudIcon, GlobeAltIcon, ServerIcon, ShareIcon } from '@heroicons/react/24/outline';

export type Kind = 'local' | 'mounted' | 's3' | 'agent';
export interface Srv { id: number; name: string; tunnel_port: number; agent_seen_at: string | null; agent_version: string | null }
export interface Node {
  id: number; name: string; kind: Kind; status: 'testing' | 'active' | 'draining' | 'retired'; health: 'ok' | 'degraded' | 'down' | 'unknown';
  config: { path?: string; server_id?: number; endpoint?: string; bucket?: string; region?: string; path_style?: boolean; prefix?: string };
  has_secret: boolean; region: string | null; tags: string | null; priority: number; readonly: number | string;
  quota_bytes: number | null; total_bytes: number | null; used_bytes: number | null; free_bytes: number | null; capacity_bytes: number | null; used_pct: number | null;
  write_mbps: number | null; read_mbps: number | null; latency_ms: number | null; last_check_at: string | null; last_error: string | null;
  status_history: { from: string | null; to: string; at: string; reason?: string | null }[]; allocations?: number;
}
export interface Rule { id: number; name: string; metric: string; op: 'gt' | 'lt'; threshold: number | string; level: 'warning' | 'error'; node_id: number | null; enabled: number | string }
export interface AlertRow { id: number; node_id: number; node_name: string | null; level: string; message: string; opened_at: string; acknowledged_by: number | null }
export interface Overview {
  summary: { nodes: number; active: number; healthy: number; down: number; capacity_bytes: number; used_bytes: number; free_bytes: number; open_alerts: number };
  nodes: Node[]; alerts: AlertRow[]; rules: Rule[]; flow: Record<string, string[]>; servers: Srv[];
}
export interface Check { at: string; health: string; used_bytes: number | null; total_bytes: number | null; write_mbps: number | null; read_mbps: number | null; latency_ms: number | null; error: string | null }
export interface Allocation { id: number; scope_type: string; scope_id: string; quota_bytes: number | null; note: string | null; created_at: string }

export const KIND: Record<Kind, { label: string; icon: typeof ServerIcon; tone: 'blue' | 'purple' | 'amber' }> = {
  local: { label: 'Local disk', icon: ServerIcon, tone: 'blue' },
  mounted: { label: 'Network mount', icon: ShareIcon, tone: 'purple' },
  s3: { label: 'S3-compatible', icon: CloudIcon, tone: 'amber' },
  agent: { label: 'VPS / node', icon: GlobeAltIcon, tone: 'purple' },
};
export const STATUS_TONE: Record<string, 'gray' | 'green' | 'amber' | 'blue'> = { testing: 'blue', active: 'green', draining: 'amber', retired: 'gray' };
export const ACTION: Record<string, string> = { active: 'Activate', draining: 'Drain', retired: 'Retire', testing: 'Return to testing' };
export const METRICS: Record<string, string> = { used_pct: 'Used space (%)', free_gb: 'Free space (GB)', latency_ms: 'Response time (ms)', write_mbps: 'Write speed (MB/s)', down: 'Node unreachable' };
export const n2 = (v: number | null | undefined, unit: string) => (v == null ? '—' : `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })} ${unit}`);

export interface FileRow { id: number; app: string; object_key: string; name: string; size: number; mime: string | null; created_at: string; copies: number | string }
export interface Listing { apps: { app: string; files: number; bytes: number }[]; app: string; prefix: string; folders: { name: string; files: number; bytes: number }[]; files: FileRow[] }
export interface Placement { node_id: number; node_name: string | null; status: string; health: string | null; node_status: string | null; verified_at: string | null; error: string | null }
export interface Task { id: number; type: string; status: string; object_key: string | null; app: string | null; from_name: string | null; to_name: string | null; attempts: number; error: string | null; reason: string | null; updated_at: string }
export interface FileDetail extends FileRow { sha256: string; placements: Placement[]; tasks: Task[]; policy: { name?: string; replicas: number | string; strategy: string } }
export interface Policy { id: number; name: string; replicas: number | string; min_regions?: number | string; strategy: string; node_tags: string | null; app: string | null; is_default: number | string; files?: number | string }

export const STRATEGY: Record<string, string> = { capacity: 'Most free space', priority: 'Node priority', round_robin: 'Spread evenly' };
export const TASK_TONE: Record<string, 'blue' | 'amber' | 'green' | 'red'> = { pending: 'blue', running: 'amber', done: 'green', failed: 'red' };

export interface BackupRun { id: number; status: string; trigger: string; size: number | null; object_id: number | null; object_key: string | null; error: string | null; started_at: string; finished_at: string | null }
export interface Backup { id: number; name: string; schedule: string; at_hour: number | string; keep: number | string; exclude_tables: string | null; policy_id: number | null; enabled: number | string; last_run_at: string | null; runs: BackupRun[] }
export const RUN_TONE: Record<string, 'blue' | 'green' | 'red'> = { running: 'blue', ok: 'green', failed: 'red' };

export interface RestoreStatus { state: 'running' | 'done' | 'failed'; backup: string; taken_at: string; by: string; started_at: string; finished_at?: string; step: string; error?: string; safety: boolean; safety_key?: string; log: string[] }
