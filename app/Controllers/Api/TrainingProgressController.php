<?php

namespace App\Controllers\Api;

use App\Models\TrainingJobModel;

/**
 * Live training progress.
 *
 *   POST /api/training/{id}/report-token   issue the runner's token, shown once        (models.manage)
 *   POST /api/training/{id}/progress       runner → Studio: step, loss, ETA, machine     (Bearer job token)
 *   GET  /api/training/{id}/live           progress, chart points and log tail           (models.view)
 *   GET  /api/training/{id}/files/{name}   runner fetches its job files (script, data)   (Bearer job token)
 *   PUT  /api/training/{id}/output/{name}  runner uploads its result (raw body)          (Bearer job token)
 *
 * Job files live in writable/training/{id}/files/, results land in writable/training/{id}/output/ — so a model
 * trained on a node comes back to the server instead of staying on that machine.
 *
 * The runner is the training script itself or `aistudio-train-report`, which follows a training log.
 */
class TrainingProgressController extends BaseApiController
{
    private const LOG_LIMIT = 200_000; // characters of log kept per job

    public function reportToken($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $jobs = new TrainingJobModel();
        if (! $jobs->find((int) $id)) return $this->failure('Job not found.', 404);
        $token = 'aist_' . bin2hex(random_bytes(24));
        $jobs->update((int) $id, ['report_token_hash' => hash('sha256', $token)]);
        $base = rtrim((string) config('App')->baseURL, '/');
        return $this->ok([
            'token'    => $token,
            'url'      => "{$base}/api/training/{$id}/progress",
            'env'      => "AISTUDIO_JOB_URL={$base}/api/training/{$id}/progress\nAISTUDIO_JOB_TOKEN={$token}",
        ], 'Report token created. It replaces any earlier token for this job.', 201);
    }

    /** The job row when the request carries its Bearer report token, else null. */
    private function jobForToken($id): ?array
    {
        $job  = (new TrainingJobModel())->find((int) $id);
        $auth = (string) $this->request->getHeaderLine('Authorization');
        $tok  = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if (! $job || ! $job['report_token_hash'] || ! hash_equals($job['report_token_hash'], hash('sha256', $tok))) return null;
        return $job;
    }

    private static function safeName(string $name): ?string
    {
        $name = basename($name);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/', $name) ? $name : null;
    }

    public function file($id = null, $name = null)
    {
        if (! $this->jobForToken($id)) return $this->failure('Invalid job or token.', 401);
        $name = self::safeName((string) $name);
        $path = $name ? WRITEPATH . 'training/' . (int) $id . '/files/' . $name : null;
        if (! $path || ! is_file($path)) return $this->failure('No such job file.', 404);
        @set_time_limit(0);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        readfile($path);
        exit;
    }

    public function output($id = null, $name = null)
    {
        if (! $this->jobForToken($id)) return $this->failure('Invalid job or token.', 401);
        $name = self::safeName((string) $name);
        if (! $name) return $this->failure('Give the file a plain name (letters, digits, . _ -).', 422);
        $dir = WRITEPATH . 'training/' . (int) $id . '/output/';
        if (! is_dir($dir)) mkdir($dir, 0775, true);
        @set_time_limit(0);
        // Large results come in pieces (?part=0..parts-1): the framework reads a request body into memory, so one
        // 450 MB body exceeded PHP's memory limit. Part 0 starts the file, each part appends, the last one finishes it.
        $part  = max(0, (int) ($this->request->getGet('part') ?? 0));
        $parts = max(1, (int) ($this->request->getGet('parts') ?? 1));
        if ($part >= $parts) return $this->failure('part must be below parts.', 422);
        $tmp  = $dir . $name . '.part';
        if ($part > 0 && ! is_file($tmp)) return $this->failure('Upload part 0 first.', 409);
        $body = $this->request->getBody() ?? '';
        if ($body === '') return $this->failure('Empty upload.', 422);
        file_put_contents($tmp, $body, $part === 0 ? 0 : FILE_APPEND);
        clearstatcache(true, $tmp);                                      // is_file() above cached the old size
        $n = filesize($tmp);
        if ($part < $parts - 1) return $this->ok(['part' => $part, 'received_bytes' => $n], 'Part stored.');
        rename($tmp, $dir . $name);
        return $this->ok(['name' => $name, 'size_bytes' => $n, 'sha256' => hash_file('sha256', $dir . $name)], 'Result stored on the server.', 201);
    }

    public function progress($id = null)
    {
        $jobs = new TrainingJobModel();
        $job  = $this->jobForToken($id);
        if (! $job) return $this->failure('Invalid job or token.', 401);

        $in  = $this->input();
        $now = date('Y-m-d H:i:s');
        $num = static fn ($k) => isset($in[$k]) && is_numeric($in[$k]) ? $in[$k] + 0 : null;
        $set = ['last_report_at' => $now];

        foreach (['current_step' => 'step', 'total_steps' => 'total_steps', 'epoch' => 'epoch', 'eta_seconds' => 'eta_seconds'] as $col => $key) {
            if (($v = $num($key)) !== null) $set[$col] = $v;
        }
        if (isset($set['current_step'], $set['total_steps']) && $set['total_steps'] > 0) {
            $set['progress_pct'] = round(min(100, $set['current_step'] / $set['total_steps'] * 100), 2);
        }
        if (($p = $num('progress_pct')) !== null) $set['progress_pct'] = max(0, min(100, $p));
        if (! empty($in['phase'])) $set['phase'] = mb_substr((string) $in['phase'], 0, 40);
        if (isset($in['server_id']) && is_numeric($in['server_id'])) $set['server_id'] = (int) $in['server_id'];

        if (in_array($job['status'], ['cancelled'], true)) return $this->ok(['cancelled' => true]); // late reports from a stopped run
        $status = $in['status'] ?? null;
        if (in_array($status, ['running', 'completed', 'failed'], true)) {
            $set['status'] = $status;
            if ($status === 'running' && empty($job['started_at'])) $set['started_at'] = $now;
            if ($status !== 'running') {
                $set['finished_at'] = $now;
                $set['eta_seconds'] = 0;
                if ($status === 'completed') $set['progress_pct'] = 100;
            }
        } elseif ($job['status'] !== 'running' && $job['status'] !== 'completed' && $job['status'] !== 'failed') {
            $set['status'] = 'running';
            $set['started_at'] = $job['started_at'] ?: $now;
        }

        // Latest figures (machine, speed, evaluation results) merged into one JSON blob.
        $live = json_decode((string) ($job['live'] ?? ''), true) ?: [];
        foreach (['loss', 'lr', 'samples_per_sec', 'sec_per_step', 'cpu_pct', 'ram_pct', 'gpu_pct', 'vram_pct', 'vram_used_mb', 'vram_total_mb',
                     'gpu_temp_c', 'gpu_power_w', 'gpu_name', 'host', 'device'] as $k) {
            if (array_key_exists($k, $in)) $live[$k] = $in[$k];
        }
        if (isset($in['metrics']) && is_array($in['metrics'])) {
            $live['metrics'] = array_merge($live['metrics'] ?? [], $in['metrics']);
        }
        $set['live'] = json_encode($live, JSON_UNESCAPED_UNICODE);

        if (! empty($in['log']) && is_array($in['log'])) {
            $lines = array_map(static fn ($l) => mb_substr(str_replace("\r", '', (string) $l), 0, 2000), array_slice($in['log'], -200));
            $logs  = ($job['logs'] ?? '') . implode("\n", $lines) . "\n";
            $set['logs'] = mb_strlen($logs) > self::LOG_LIMIT ? mb_substr($logs, -self::LOG_LIMIT) : $logs;
        }
        if (($set['status'] ?? null) === 'failed') {                     // keep the reason where the Training page shows it
            $why = trim((string) ($in['error'] ?? ''));
            if ($why === '' && ! empty($in['log']) && is_array($in['log'])) $why = (string) end($in['log']);
            if ($why !== '') $set['error'] = mb_substr($why, 0, 2000);
        }
        $jobs->update((int) $id, $set);
        if (isset($set['status']) && in_array($set['status'], ['completed', 'failed'], true) && $job['status'] === 'running') {
            (new \App\Services\TrainingPipelineService())->finished($job, $set['status']); // notifications for pipeline jobs
        }

        // One chart point per report that carries a step or machine figures.
        if (isset($in['step']) || isset($in['gpu_pct']) || isset($in['loss'])) {
            \Config\Database::connect()->table('training_job_points')->insert([
                'job_id' => (int) $id, 'at' => $now, 'step' => $num('step'), 'loss' => $num('loss'), 'lr' => $num('lr'),
                'cpu_pct' => $num('cpu_pct'), 'gpu_pct' => $num('gpu_pct'), 'vram_pct' => $num('vram_pct'), 'gpu_temp' => $num('gpu_temp_c'),
            ]);
        }
        return $this->ok(['progress_pct' => $set['progress_pct'] ?? $job['progress_pct']]);
    }

    public function live($id = null)
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $db  = \Config\Database::connect();
        $job = $db->table('training_jobs t')
            ->select('t.*, d.name AS dataset_name, s.name AS server_name, s.gpu_model AS server_gpu')
            ->join('datasets d', 'd.id = t.dataset_id', 'left')
            ->join('servers s', 's.id = t.server_id', 'left')
            ->where('t.id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);

        // Up to ~400 evenly spaced chart points.
        $total  = (int) $db->table('training_job_points')->where('job_id', (int) $id)->countAllResults();
        $every  = max(1, (int) ceil($total / 400));
        $points = $db->query('SELECT at, step, loss, lr, cpu_pct, gpu_pct, vram_pct, gpu_temp FROM (
                SELECT p.*, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM training_job_points p WHERE job_id = ?) x
            WHERE (rn - 1) % ? = 0 OR rn = ? ORDER BY rn', [(int) $id, $every, $total])->getResultArray();

        $job['live']       = json_decode((string) ($job['live'] ?? ''), true) ?: null;
        $job['log_tail']   = implode("\n", array_slice(explode("\n", rtrim((string) $job['logs'])), -300));
        $job['has_token']  = ! empty($job['report_token_hash']);
        $job['stale']      = $job['status'] === 'running' && $job['last_report_at'] && strtotime($job['last_report_at']) < time() - 180;
        unset($job['logs'], $job['report_token_hash']);
        return $this->ok(['job' => $job, 'points' => $points]);
    }
}
