<?php

namespace App\Services;

/**
 * Compute allocations: which nodes a user may place training jobs on, and how much CPU/GPU they may hold there.
 *
 * A user can use a node only through a `node_allocations` row (not expired). What a user holds on a node is
 * the sum of cpu_cores/gpus over their jobs' `training_job_nodes` rows that are still active. Admins may
 * place outside an allocation; such rows are stored with override = 1.
 */
class ComputeAllocationService
{
    public const ACTIVE = ['pending', 'assigned', 'running'];
    public const NODE_STATUSES = ['pending', 'assigned', 'running', 'completed', 'failed', 'cancelled'];

    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** Allocations of one user (or all when null) with node info and current usage. */
    public function allocations(?int $userId = null): array
    {
        $b = $this->db->table('node_allocations a')
            ->select('a.*, s.name AS server_name, s.status AS server_status, s.cpu_cores AS server_cpu_cores,
                      s.gpu_count AS server_gpu_count, s.gpu_model, s.gpu_vram_gb, s.ram_gb, s.allow_training,
                      u.name AS user_name, u.email AS user_email')
            ->join('servers s', 's.id = a.server_id')
            ->join('users u', 'u.id = a.user_id', 'left')
            ->where('s.deleted_at', null)
            ->orderBy('u.name')->orderBy('s.name');
        if ($userId !== null) $b->where('a.user_id', $userId);
        $rows = $b->get()->getResultArray();

        $usage = $this->usageByUserNode();
        foreach ($rows as &$r) {
            $u = $usage[$r['user_id'] . ':' . $r['server_id']] ?? ['cpu' => 0, 'gpus' => 0, 'jobs' => 0];
            $r['used_cpu_cores'] = $u['cpu'];
            $r['used_gpus']      = $u['gpus'];
            $r['active_jobs']    = $u['jobs'];
            $r['free_cpu_cores'] = max(0, (int) $r['max_cpu_cores'] - $u['cpu']);
            $r['free_gpus']      = max(0, (int) $r['max_gpus'] - $u['gpus']);
            $r['expired']        = $r['expires_at'] !== null && strtotime($r['expires_at']) < time();
        }
        return $rows;
    }

    /** "userId:serverId" => {cpu, gpus, jobs} over active placements. */
    public function usageByUserNode(?int $excludeJobId = null): array
    {
        $b = $this->db->table('training_job_nodes n')
            ->select('j.created_by AS user_id, n.server_id, SUM(n.cpu_cores) AS cpu, SUM(n.gpus) AS gpus, COUNT(DISTINCT n.training_job_id) AS jobs')
            ->join('training_jobs j', 'j.id = n.training_job_id')
            ->whereIn('n.status', self::ACTIVE)
            ->groupBy(['j.created_by', 'n.server_id']);
        if ($excludeJobId) $b->where('n.training_job_id <>', $excludeJobId);
        $out = [];
        foreach ($b->get()->getResultArray() as $r) {
            $out[$r['user_id'] . ':' . $r['server_id']] = ['cpu' => (int) $r['cpu'], 'gpus' => (int) $r['gpus'], 'jobs' => (int) $r['jobs']];
        }
        return $out;
    }

    /** Per-node totals over all users' active placements: serverId => {cpu, gpus, jobs}. */
    public function usageByNode(): array
    {
        $rows = $this->db->table('training_job_nodes')
            ->select('server_id, SUM(cpu_cores) AS cpu, SUM(gpus) AS gpus, COUNT(DISTINCT training_job_id) AS jobs')
            ->whereIn('status', self::ACTIVE)->groupBy('server_id')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) $out[(int) $r['server_id']] = ['cpu' => (int) $r['cpu'], 'gpus' => (int) $r['gpus'], 'jobs' => (int) $r['jobs']];
        return $out;
    }

    /**
     * Check a placement request for $userId. $nodes = [{server_id, cpu_cores, gpus}].
     * Returns a list of human-readable problems (empty = allowed). $excludeJobId ignores that job's own rows.
     */
    public function check(int $userId, array $nodes, ?int $excludeJobId = null): array
    {
        if (! $nodes) return ['Choose at least one node.'];
        $alloc = [];
        foreach ($this->db->table('node_allocations')->where('user_id', $userId)->get()->getResultArray() as $a) {
            $alloc[(int) $a['server_id']] = $a;
        }
        $usage    = $this->usageByUserNode($excludeJobId);
        $problems = [];
        $seen     = [];
        foreach ($nodes as $n) {
            $sid = (int) $n['server_id'];
            if (isset($seen[$sid])) { $problems[] = "Node #{$sid} is listed twice."; continue; }
            $seen[$sid] = true;
            $server = $this->db->table('servers')->where('id', $sid)->where('deleted_at', null)->get()->getRowArray();
            $label  = $server['name'] ?? "#{$sid}";
            if (! $server) { $problems[] = "Node {$label} does not exist."; continue; }
            if (! (int) ($server['is_enabled'] ?? 1)) { $problems[] = "Node {$label} is disabled."; continue; }
            if (! (int) $server['allow_training']) { $problems[] = "Node {$label} is not open for training."; continue; }
            $a = $alloc[$sid] ?? null;
            if (! $a) { $problems[] = "You have no allocation on node {$label}."; continue; }
            if ($a['expires_at'] !== null && strtotime($a['expires_at']) < time()) { $problems[] = "Your allocation on {$label} has expired."; continue; }
            $cpu  = (int) ($n['cpu_cores'] ?? 0);
            $gpus = (int) ($n['gpus'] ?? 0);
            if ($cpu < 1 && $gpus < 1) { $problems[] = "Request at least one CPU core or GPU on {$label}."; continue; }
            $u = $usage["{$userId}:{$sid}"] ?? ['cpu' => 0, 'gpus' => 0, 'jobs' => 0];
            if ($u['cpu'] + $cpu > (int) $a['max_cpu_cores']) {
                $problems[] = "CPU on {$label}: requested {$cpu}, " . max(0, $a['max_cpu_cores'] - $u['cpu']) . " of {$a['max_cpu_cores']} cores free in your allocation.";
            }
            if ($u['gpus'] + $gpus > (int) $a['max_gpus']) {
                $problems[] = "GPU on {$label}: requested {$gpus}, " . max(0, $a['max_gpus'] - $u['gpus']) . " of {$a['max_gpus']} GPUs free in your allocation.";
            }
            if ($u['jobs'] + 1 > (int) $a['max_concurrent_jobs']) {
                $problems[] = "Concurrent jobs on {$label}: limit {$a['max_concurrent_jobs']} reached.";
            }
        }
        return $problems;
    }

    /** Replace a job's placement. Existing rows for nodes still listed keep their status. */
    public function place(int $jobId, array $nodes, int $byUserId, bool $override): void
    {
        $now      = date('Y-m-d H:i:s');
        $existing = [];
        foreach ($this->db->table('training_job_nodes')->where('training_job_id', $jobId)->get()->getResultArray() as $r) {
            $existing[(int) $r['server_id']] = $r;
        }
        $keep = [];
        foreach ($nodes as $n) {
            $sid = (int) $n['server_id'];
            $row = [
                'cpu_cores' => max(0, (int) ($n['cpu_cores'] ?? 0)),
                'gpus'      => max(0, (int) ($n['gpus'] ?? 0)),
                'override'  => $override ? 1 : 0,
                'assigned_by' => $byUserId,
                'updated_at'  => $now,
            ];
            if (isset($existing[$sid])) {
                $this->db->table('training_job_nodes')->where('id', $existing[$sid]['id'])->update($row);
            } else {
                $this->db->table('training_job_nodes')->insert($row + [
                    'training_job_id' => $jobId, 'server_id' => $sid, 'status' => 'pending', 'created_at' => $now,
                ]);
            }
            $keep[] = $sid;
        }
        $del = $this->db->table('training_job_nodes')->where('training_job_id', $jobId);
        if ($keep) $del->whereNotIn('server_id', $keep);
        $del->delete();
        $this->refreshSummary($jobId);
    }

    /** Placement rows of a job with node names. */
    public function nodesOf(int $jobId): array
    {
        return $this->db->table('training_job_nodes n')
            ->select('n.*, s.name AS server_name, s.status AS server_status, s.gpu_model')
            ->join('servers s', 's.id = n.server_id', 'left')
            ->where('n.training_job_id', $jobId)->orderBy('n.id')->get()->getResultArray();
    }

    /** Placement rows for many jobs: jobId => rows. */
    public function nodesFor(array $jobIds): array
    {
        if (! $jobIds) return [];
        $out = [];
        $rows = $this->db->table('training_job_nodes n')
            ->select('n.*, s.name AS server_name, s.status AS server_status, s.gpu_model')
            ->join('servers s', 's.id = n.server_id', 'left')
            ->whereIn('n.training_job_id', $jobIds)->orderBy('n.id')->get()->getResultArray();
        foreach ($rows as $r) $out[(int) $r['training_job_id']][] = $r;
        return $out;
    }

    /** Update one node's status for a job (runner or admin). */
    public function setNodeStatus(int $jobId, int $serverId, string $status, ?int $progress, ?string $message): bool
    {
        $row = $this->db->table('training_job_nodes')->where(['training_job_id' => $jobId, 'server_id' => $serverId])->get()->getRowArray();
        if (! $row) return false;
        $now = date('Y-m-d H:i:s');
        $upd = ['status' => $status, 'updated_at' => $now];
        if ($progress !== null) $upd['progress'] = max(0, min(100, $progress));
        if ($message !== null) $upd['message'] = mb_substr($message, 0, 255);
        if ($status === 'running' && ! $row['started_at']) $upd['started_at'] = $now;
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) $upd['finished_at'] = $now;
        if ($status === 'completed') $upd['progress'] = 100;
        $this->db->table('training_job_nodes')->where('id', $row['id'])->update($upd);
        $this->refreshSummary($jobId);
        return true;
    }

    /** training_jobs.placement_status: unplaced | pending | running | completed | failed | partial | cancelled. */
    public function refreshSummary(int $jobId): string
    {
        $st = array_column($this->db->table('training_job_nodes')->select('status')->where('training_job_id', $jobId)->get()->getResultArray(), 'status');
        $s = match (true) {
            ! $st                                      => 'unplaced',
            in_array('running', $st, true)             => 'running',
            in_array('failed', $st, true) && in_array('completed', $st, true) => 'partial',
            in_array('failed', $st, true)              => 'failed',
            count(array_unique($st)) === 1             => $st[0] === 'assigned' ? 'pending' : $st[0],
            in_array('pending', $st, true) || in_array('assigned', $st, true) => 'pending',
            default                                    => 'partial',
        };
        $this->db->table('training_jobs')->where('id', $jobId)->update(['placement_status' => $s]);
        return $s;
    }

    /** Nodes open for training with capacity and current total usage. */
    public function trainingNodes(): array
    {
        $usage = $this->usageByNode();
        $rows = $this->db->table('servers')
            ->select('id, name, status, is_enabled, cpu_cores, ram_gb, gpu_model, gpu_count, gpu_vram_gb, allow_training')
            ->where('deleted_at', null)->orderBy('name')->get()->getResultArray();
        foreach ($rows as &$r) {
            $u = $usage[(int) $r['id']] ?? ['cpu' => 0, 'gpus' => 0, 'jobs' => 0];
            $r['used_cpu_cores'] = $u['cpu'];
            $r['used_gpus']      = $u['gpus'];
            $r['active_jobs']    = $u['jobs'];
        }
        return $rows;
    }
}
