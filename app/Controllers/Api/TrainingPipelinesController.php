<?php

namespace App\Controllers\Api;

use App\Libraries\TrainingRecipes;
use App\Services\TrainingPipelineService;

/**
 * Training pipelines and job control (admin).
 *
 *   GET    /api/training/pipelines              pipelines + recipes + node readiness
 *   POST   /api/training/pipelines              create
 *   PUT    /api/training/pipelines/{id}         update (name, dataset, node, hyperparams, auto_trigger, enabled, …)
 *   DELETE /api/training/pipelines/{id}
 *   POST   /api/training/pipelines/{id}/run     start a run now
 *   POST   /api/training/{id}/cancel            stop a job (a running job stops on the node within a minute)
 *   POST   /api/training/{id}/retry             queue a failed/cancelled pipeline job again
 *   POST   /api/servers/{id}/agent-token        issue a node's training-agent token + install command (shown once)
 */
class TrainingPipelinesController extends BaseApiController
{
    private function svc(): TrainingPipelineService { return new TrainingPipelineService(); }

    private function db() { return \Config\Database::connect(); }

    public function index()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $db  = $this->db();
        $svc = $this->svc();
        $rows = $db->table('training_pipelines p')
            ->select('p.*, d.name AS dataset_name, s.name AS server_name, j.status AS last_status, j.progress_pct AS last_progress, j.error AS last_error')
            ->join('datasets d', 'd.id = p.dataset_id', 'left')->join('servers s', 's.id = p.server_id', 'left')
            ->join('training_jobs j', 'j.id = p.last_job_id', 'left')->orderBy('p.id')->get()->getResultArray();
        foreach ($rows as &$p) {
            $p['hyperparams'] = json_decode((string) $p['hyperparams'], true) ?: (object) [];
            $p['pending_change'] = $svc->fingerprint($p) !== $p['last_fingerprint'];
            $p['recipe_label'] = TrainingRecipes::get($p['recipe'])['label'] ?? $p['recipe'];
        }
        $fresh = date('Y-m-d H:i:s', time() - TrainingPipelineService::AGENT_FRESH_SECONDS);
        $nodes = $db->table('servers')->select('id, name, status, is_enabled, allow_training, gpu_model, agent_seen_at, agent_version, agent_job_id, agent_token_hash IS NOT NULL AS agent_installed', false)
            ->where('deleted_at', null)->orderBy('name')->get()->getResultArray();
        foreach ($nodes as &$n) {
            $n['agent_ready'] = $n['agent_seen_at'] && $n['agent_seen_at'] >= $fresh;
            $n['agent_installed'] = (bool) $n['agent_installed'];
        }
        $recipes = [];
        foreach (TrainingRecipes::all() as $k => $r) {
            $recipes[] = ['key' => $k, 'label' => $r['label'], 'task' => $r['task'], 'needs_gpu' => $r['needs_gpu'], 'base_model' => $r['base_model'],
                          'hyperparams' => $r['hyperparams'], 'data' => $r['data']['type'], 'output' => $r['output']];
        }
        return $this->ok(['pipelines' => $rows, 'recipes' => $recipes, 'nodes' => $nodes]);
    }

    private function fields(array $in, bool $create): array
    {
        $out = [];
        foreach (['name', 'recipe', 'base_model'] as $f) if (array_key_exists($f, $in)) $out[$f] = trim((string) $in[$f]) ?: null;
        foreach (['dataset_id', 'server_id'] as $f) if (array_key_exists($f, $in)) $out[$f] = (int) $in[$f] ?: null;
        foreach (['auto_trigger', 'enabled'] as $f) if (array_key_exists($f, $in)) $out[$f] = $in[$f] ? 1 : 0;
        foreach (['min_rows', 'max_attempts'] as $f) if (array_key_exists($f, $in)) $out[$f] = max(1, (int) $in[$f]);
        if (array_key_exists('hyperparams', $in)) $out['hyperparams'] = is_array($in['hyperparams']) && $in['hyperparams'] ? json_encode($in['hyperparams']) : null;
        if ($create && empty($out['name'])) throw new \InvalidArgumentException('Give the pipeline a name.');
        if (isset($out['recipe']) && ! TrainingRecipes::get($out['recipe'])) throw new \InvalidArgumentException('Unknown recipe.');
        return $out;
    }

    private function check(array $p): ?string
    {
        $recipe = TrainingRecipes::get($p['recipe']);
        if (! $recipe) return 'Unknown recipe.';
        if ($recipe['data']['type'] === 'dataset') {
            if (empty($p['dataset_id'])) return 'This recipe trains on a dataset: choose one.';
            $d = $this->db()->table('datasets')->where('id', $p['dataset_id'])->get()->getRowArray();
            if (! $d) return 'Dataset not found.';
            if ($d['rights_status'] !== 'approved') return 'Only rights-approved datasets can feed a pipeline.';
        }
        if (! empty($p['server_id'])) {
            $s = $this->db()->table('servers')->where('id', $p['server_id'])->where('deleted_at', null)->get()->getRowArray();
            if (! $s) return 'Node not found.';
            if (! TrainingRecipes::fits($recipe, $s)) return "{$s['name']} has no NVIDIA GPU; this recipe needs one.";
        }
        return null;
    }

    public function create()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        try { $row = $this->fields($this->input(), true); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        $row += ['recipe' => 'text_sft', 'auto_trigger' => 1, 'enabled' => 1];
        if ($err = $this->check($row)) return $this->failure($err, 422);
        $row['created_by'] = $this->userId();
        $row['created_at'] = $row['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('training_pipelines')->insert($row);
        return $this->ok($this->db()->table('training_pipelines')->where('id', $this->db()->insertID())->get()->getRowArray(), 'Pipeline created. It runs when its data changes, or when you press Run now.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $p = $this->db()->table('training_pipelines')->where('id', (int) $id)->get()->getRowArray();
        if (! $p) return $this->failure('Pipeline not found.', 404);
        try { $upd = $this->fields($this->input(), false); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        if ($err = $this->check(array_merge($p, $upd))) return $this->failure($err, 422);
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('training_pipelines')->where('id', (int) $id)->update($upd);
        return $this->ok($this->db()->table('training_pipelines')->where('id', (int) $id)->get()->getRowArray(), 'Pipeline saved.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $this->db()->table('training_pipelines')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Pipeline deleted. Its past jobs stay on the Training page.');
    }

    public function run($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $p = $this->db()->table('training_pipelines')->where('id', (int) $id)->get()->getRowArray();
        if (! $p) return $this->failure('Pipeline not found.', 404);
        if ($err = $this->check($p)) return $this->failure($err, 422);
        try {
            $job = $this->svc()->trigger($p, 'started by hand', $this->userId());
        } catch (\RuntimeException $e) {
            return $this->failure($e->getMessage(), 409);
        }
        return $job['status'] === 'failed'
            ? $this->failure($job['error'] ?? 'Preparation failed.', 422, ['job_id' => $job['id']])
            : $this->ok($job, "Job #{$job['id']} queued. It starts on the next free node that can run it.", 201);
    }

    public function cancel($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        try { $this->svc()->cancel((int) $id); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 404); }
        return $this->ok(null, 'Job cancelled.');
    }

    public function retry($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        try { $this->svc()->retry((int) $id); } catch (\Throwable $e) { return $this->failure($e->getMessage(), 409); }
        return $this->ok(null, 'Job queued again.');
    }

    public function agentToken($id = null)
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        $s = $this->db()->table('servers')->where('id', (int) $id)->where('deleted_at', null)->get()->getRowArray();
        if (! $s) return $this->failure('Node not found.', 404);
        $token = 'aisa_' . bin2hex(random_bytes(24));
        $this->db()->table('servers')->where('id', (int) $id)->update(['agent_token_hash' => hash('sha256', $token)]);
        $base = rtrim((string) config('App')->baseURL, '/');
        return $this->ok([
            'token'   => $token,
            'command' => "curl -fsSL {$base}/node/aistudio-agent.sh | bash -s -- --server {$base} --token {$token}",
        ], 'Agent token created. It replaces any earlier one for this node.', 201);
    }
}
