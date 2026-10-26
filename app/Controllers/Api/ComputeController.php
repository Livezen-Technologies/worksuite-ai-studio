<?php

namespace App\Controllers\Api;

use App\Services\ComputeAllocationService;

/**
 * Compute allocations (admin) — who may use which node, with how much CPU/GPU, and job placement overrides.
 *
 *   GET    /api/compute/overview               nodes (capacity + usage), allocations, users
 *   POST   /api/compute/allocations            {user_id, server_id, max_cpu_cores, max_gpus, max_concurrent_jobs, expires_at?, notes?} (upsert)
 *   PUT    /api/compute/allocations/{id}       same fields
 *   DELETE /api/compute/allocations/{id}
 *   PUT    /api/compute/nodes/{id}             {gpu_count, gpu_vram_gb, allow_training, cpu_cores}
 *   GET    /api/compute/usage                  per user × node usage + every active placement
 *   GET    /api/compute/jobs                   all jobs with placement (admin view)
 *   PUT    /api/compute/jobs/{id}/placement    {nodes:[{server_id,cpu_cores,gpus}], override?: bool}
 *   PUT    /api/compute/jobs/{id}/nodes/{sid}  {status, progress?, message?}  (also what a node runner will call)
 */
class ComputeController extends BaseApiController
{
    private function svc(): ComputeAllocationService { return new ComputeAllocationService(); }
    private function db() { return \Config\Database::connect(); }

    public function overview()
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        return $this->ok([
            'nodes'       => $this->svc()->trainingNodes(),
            'allocations' => $this->svc()->allocations(),
            'users'       => $this->db()->table('users u')->select('u.id, u.name, u.email, r.name AS role')
                ->join('roles r', 'r.id = u.role_id', 'left')->where('u.deleted_at', null)->orderBy('u.name')->get()->getResultArray(),
        ]);
    }

    private function allocationFields(array $in): array
    {
        $f = [];
        foreach (['max_cpu_cores', 'max_gpus', 'max_concurrent_jobs'] as $k) {
            if (array_key_exists($k, $in)) $f[$k] = max(0, (int) $in[$k]);
        }
        if (array_key_exists('expires_at', $in)) $f['expires_at'] = $in['expires_at'] ? date('Y-m-d H:i:s', strtotime((string) $in['expires_at'])) : null;
        if (array_key_exists('notes', $in)) $f['notes'] = $in['notes'] ? mb_substr((string) $in['notes'], 0, 255) : null;
        return $f;
    }

    public function allocationSave()
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $in  = $this->input();
        $uid = (int) ($in['user_id'] ?? 0);
        $sid = (int) ($in['server_id'] ?? 0);
        if (! $uid || ! $sid) return $this->failure('user_id and server_id are required.', 422);
        if (! $this->db()->table('users')->where('id', $uid)->countAllResults()) return $this->failure('User not found.', 404);
        if (! $this->db()->table('servers')->where('id', $sid)->where('deleted_at', null)->countAllResults()) return $this->failure('Node not found.', 404);

        $now = date('Y-m-d H:i:s');
        $f   = $this->allocationFields($in) + ['updated_at' => $now];
        $t   = $this->db()->table('node_allocations');
        $row = $t->where(['user_id' => $uid, 'server_id' => $sid])->get()->getRowArray();
        if ($row) {
            $this->db()->table('node_allocations')->where('id', $row['id'])->update($f);
        } else {
            $this->db()->table('node_allocations')->insert($f + ['user_id' => $uid, 'server_id' => $sid, 'created_by' => $this->userId(), 'created_at' => $now]);
        }
        return $this->ok($this->svc()->allocations(), 'Allocation saved.');
    }

    public function allocationUpdate($id = null)
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        if (! $this->db()->table('node_allocations')->where('id', (int) $id)->countAllResults()) return $this->failure('Allocation not found.', 404);
        $f = $this->allocationFields($this->input());
        if ($f) $this->db()->table('node_allocations')->where('id', (int) $id)->update($f + ['updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok($this->svc()->allocations(), 'Allocation updated.');
    }

    public function allocationDelete($id = null)
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $this->db()->table('node_allocations')->where('id', (int) $id)->delete();
        return $this->ok($this->svc()->allocations(), 'Allocation removed. Jobs already placed keep their placement.');
    }

    public function nodeUpdate($id = null)
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $in  = $this->input();
        $upd = [];
        if (array_key_exists('gpu_count', $in)) $upd['gpu_count'] = max(0, (int) $in['gpu_count']);
        if (array_key_exists('gpu_vram_gb', $in)) $upd['gpu_vram_gb'] = $in['gpu_vram_gb'] === null || $in['gpu_vram_gb'] === '' ? null : (float) $in['gpu_vram_gb'];
        if (array_key_exists('cpu_cores', $in)) $upd['cpu_cores'] = $in['cpu_cores'] === null || $in['cpu_cores'] === '' ? null : max(0, (int) $in['cpu_cores']);
        if (array_key_exists('allow_training', $in)) $upd['allow_training'] = $in['allow_training'] ? 1 : 0;
        if (! $this->db()->table('servers')->where('id', (int) $id)->where('deleted_at', null)->countAllResults()) return $this->failure('Node not found.', 404);
        if ($upd) $this->db()->table('servers')->where('id', (int) $id)->update($upd);
        return $this->ok($this->svc()->trainingNodes(), 'Node updated.');
    }

    public function usage()
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $placements = $this->db()->table('training_job_nodes n')
            ->select('n.*, j.name AS job_name, j.created_by AS user_id, u.name AS user_name, s.name AS server_name')
            ->join('training_jobs j', 'j.id = n.training_job_id')
            ->join('users u', 'u.id = j.created_by', 'left')
            ->join('servers s', 's.id = n.server_id', 'left')
            ->whereIn('n.status', ComputeAllocationService::ACTIVE)
            ->orderBy('u.name')->get()->getResultArray();
        return $this->ok([
            'allocations' => $this->svc()->allocations(),
            'nodes'       => $this->svc()->trainingNodes(),
            'placements'  => $placements,
        ]);
    }

    public function jobs()
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $jobs = $this->db()->table('training_jobs t')
            ->select('t.id, t.name, t.base_model, t.status, t.placement_status, t.created_at, t.created_by, u.name AS user_name, d.name AS dataset_name')
            ->join('users u', 'u.id = t.created_by', 'left')
            ->join('datasets d', 'd.id = t.dataset_id', 'left')
            ->orderBy('t.id', 'DESC')->get()->getResultArray();
        $map = $this->svc()->nodesFor(array_map(fn ($j) => (int) $j['id'], $jobs));
        foreach ($jobs as &$j) $j['nodes'] = $map[(int) $j['id']] ?? [];
        return $this->ok($jobs);
    }

    public function placement($id = null)
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $job = $this->db()->table('training_jobs')->where('id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);
        $in    = $this->input();
        $nodes = [];
        foreach ((array) ($in['nodes'] ?? []) as $n) {
            if (is_array($n) && ! empty($n['server_id'])) {
                $nodes[] = ['server_id' => (int) $n['server_id'], 'cpu_cores' => max(0, (int) ($n['cpu_cores'] ?? 0)), 'gpus' => max(0, (int) ($n['gpus'] ?? 0))];
            }
        }
        $override = ! empty($in['override']);
        if (! $override) {
            $problems = $job['created_by'] ? $this->svc()->check((int) $job['created_by'], $nodes, (int) $id) : ['The job has no owner; use override.'];
            if ($problems) return $this->failure(implode(' ', $problems) . ' Tick "override" to place it anyway.', 409, ['problems' => $problems]);
        }
        $this->svc()->place((int) $id, $nodes, $this->userId(), $override);
        return $this->ok($this->svc()->nodesOf((int) $id), 'Placement saved.');
    }

    public function nodeStatus($id = null, $serverId = null)
    {
        if ($r = $this->requirePermission('compute.manage')) return $r;
        $in     = $this->input();
        $status = (string) ($in['status'] ?? '');
        if (! in_array($status, ComputeAllocationService::NODE_STATUSES, true)) return $this->failure('Unknown status.', 422);
        $ok = $this->svc()->setNodeStatus((int) $id, (int) $serverId, $status,
            isset($in['progress']) ? (int) $in['progress'] : null, isset($in['message']) ? (string) $in['message'] : null);
        if (! $ok) return $this->failure('That job is not placed on this node.', 404);
        return $this->ok($this->svc()->nodesOf((int) $id), 'Node status updated.');
    }
}
