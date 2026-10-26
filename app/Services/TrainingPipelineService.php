<?php

namespace App\Services;

use App\Libraries\TrainingRecipes;

/**
 * Automated training (scheduler → server → node agent).
 *
 *   tick()     every minute: trigger pipelines whose data changed, reap stalled jobs (retry, then fail), notify.
 *   trigger()  create a job, stage its files (code + data), validate the data → queued, or failed with the reason.
 *   claim()    called from a node agent's heartbeat: hand it the oldest queued job it can run (atomic).
 *
 * A job is staged in writable/training/{id}/files/ with a manifest.txt; the node's generic runner fetches exactly
 * those files with the job's report token, so nothing on the node is reachable from outside.
 */
class TrainingPipelineService
{
    public const AGENT_FRESH_SECONDS = 90;   // an agent seen within this is "ready"
    public const STALL_SECONDS       = 900;  // a running job with neither a report nor a live agent this long is stalled

    private $db;
    private NotificationService $notes;

    public function __construct()
    {
        $this->db    = \Config\Database::connect();
        $this->notes = new NotificationService();
    }

    // ------------------------------------------------------------------ scheduler

    public function tick(): array
    {
        $out = ['triggered' => [], 'reaped' => []];
        foreach ($this->db->table('training_pipelines')->where(['enabled' => 1, 'auto_trigger' => 1])->get()->getResultArray() as $p) {
            if ($this->active((int) $p['id'])) continue;                     // one job per pipeline at a time
            $fp = $this->fingerprint($p);
            if ($fp === null || $fp === $p['last_fingerprint']) continue;
            $why = $p['last_fingerprint'] ? 'dataset changed' : 'first run';
            $out['triggered'][] = $this->trigger($p, $why)['id'] ?? null;
        }
        $out['reaped'] = $this->reap();
        return $out;
    }

    /** Fingerprint of the pipeline's data: a change starts a new run. */
    public function fingerprint(array $p): ?string
    {
        $recipe = TrainingRecipes::get($p['recipe']);
        if (! $recipe) return null;
        if ($recipe['data']['type'] === 'prepared') {
            $parts = [];
            foreach ($recipe['data']['files'] as $f) {
                $path = $recipe['data']['dir'] . '/' . $f;
                if (! is_file($path)) return null;
                $parts[] = $f . ':' . filesize($path) . ':' . filemtime($path);
            }
            return hash('sha256', implode('|', $parts));
        }
        $d = $this->db->table('datasets')->where('id', (int) $p['dataset_id'])->get()->getRowArray();
        if (! $d) return null;
        return hash('sha256', implode('|', [$d['id'], $d['item_count'], $d['manifest_hash'] ?? '', $d['split_hash'] ?? '', $d['updated_at'] ?? '', $d['rights_status']]));
    }

    private function active(int $pipelineId): bool
    {
        return $this->db->table('training_jobs')->where('pipeline_id', $pipelineId)
            ->whereIn('status', ['preparing', 'queued', 'running'])->countAllResults() > 0;
    }

    // ------------------------------------------------------------------ trigger: stage + validate

    public function trigger(array $p, string $reason, ?int $userId = null): array
    {
        $recipe = TrainingRecipes::get($p['recipe']);
        if (! $recipe) throw new \InvalidArgumentException('Unknown recipe.');
        if ($this->active((int) $p['id'])) throw new \RuntimeException('This pipeline already has a job in progress.');
        $now  = date('Y-m-d H:i:s');
        $hp   = array_merge($recipe['hyperparams'], json_decode((string) ($p['hyperparams'] ?? ''), true) ?: []);
        $base = $p['base_model'] ?: $recipe['base_model'];
        $this->db->table('training_jobs')->insert([
            'pipeline_id' => $p['id'], 'recipe' => $p['recipe'], 'trigger_reason' => $reason,
            'name' => $p['name'] . ' — ' . date('Y-m-d H:i'), 'dataset_id' => $p['dataset_id'], 'server_id' => $p['server_id'],
            'base_model' => $base, 'method' => $recipe['task'] === 'text' ? 'lora' : 'finetune', 'task' => $recipe['task'],
            'hyperparams' => json_encode($hp), 'status' => 'preparing', 'phase' => 'staging data', 'max_attempts' => (int) $p['max_attempts'] ?: 2,
            'seed' => 2026, 'created_by' => $userId ?? $p['created_by'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id  = (int) $this->db->insertID();
        $fp  = $this->fingerprint($p);
        $this->db->table('training_pipelines')->where('id', $p['id'])
            ->update(['last_job_id' => $id, 'last_triggered_at' => $now, 'last_fingerprint' => $fp, 'updated_at' => $now]);
        try {
            $facts = $this->stage($id, $p, $recipe, $hp, $base);
            $this->db->table('training_jobs')->where('id', $id)->update([
                'status' => 'queued', 'phase' => 'waiting for a node', 'updated_at' => date('Y-m-d H:i:s'),
                'logs' => "Triggered: {$reason}\nStaged: {$facts}\n", 'dataset_manifest_hash' => $facts === '' ? null : hash('sha256', $facts),
            ]);
            $this->notify("Training queued: {$p['name']}", "Job #{$id} ({$reason}). {$facts}. It starts on the next free node that can run it.", 'info');
        } catch (\Throwable $e) {
            $this->fail($id, 'Preparation failed: ' . $e->getMessage(), false);
        }
        return $this->db->table('training_jobs')->where('id', $id)->get()->getRowArray();
    }

    /** Stage code + data for job $id and validate the data. Returns a one-line summary. Throws on invalid data. */
    private function stage(int $id, array $p, array $recipe, array $hp, string $base): string
    {
        $dir = WRITEPATH . 'training/' . $id . '/files/';
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create the job folder');
        $names = [];
        $code  = TrainingRecipes::codeDir($p['recipe']);
        foreach (['train.py', 'requirements.txt'] as $f) {
            if (! is_file($code . $f)) throw new \RuntimeException("recipe file {$f} is missing on the server");
            copy($code . $f, $dir . $f); $names[] = $f;
        }
        foreach ($recipe['extra'] as $rel) {
            copy(ROOTPATH . $rel, $dir . basename($rel)); $names[] = basename($rel);
        }
        $env = ['BASE' => $base, 'RECIPE' => $p['recipe'], 'JOB_ID' => (string) $id];
        foreach ($hp as $k => $v) $env[strtoupper($k)] = is_scalar($v) ? (string) $v : json_encode($v);

        if ($recipe['data']['type'] === 'prepared') {
            $rows = [];
            foreach ($recipe['data']['files'] as $f) {
                $src = $recipe['data']['dir'] . '/' . $f;
                if (! is_file($src) || filesize($src) < 1024) throw new \RuntimeException("prepared data {$f} is missing or empty");
                if (! @link($src, $dir . $f) && ! copy($src, $dir . $f)) throw new \RuntimeException("cannot stage {$f}");
                $names[] = $f; $rows[] = $f . ' ' . round(filesize($src) / 1048576) . ' MB';
            }
            $summary = 'data: ' . implode(', ', $rows);
        } else {
            $summary = $this->stageDataset($dir, (int) $p['dataset_id'], (int) $p['min_rows'], (int) ($hp['max_rows'] ?? 0));
            array_push($names, 'train.jsonl', 'dev.jsonl');
        }
        file_put_contents($dir . 'job.env', implode("\n", array_map(fn ($k, $v) => $k . '=' . preg_replace('/[^\w.:\/+-]/', '', $v), array_keys($env), $env)) . "\n");
        $names[] = 'job.env';
        file_put_contents($dir . 'manifest.txt', implode("\n", $names) . "\n");
        return $summary;
    }

    /** Export a dataset to train.jsonl / dev.jsonl with validation. */
    private function stageDataset(string $dir, int $datasetId, int $minRows, int $maxRows): string
    {
        $d = $this->db->table('datasets')->where('id', $datasetId)->get()->getRowArray();
        if (! $d) throw new \RuntimeException('the dataset no longer exists');
        if ($d['rights_status'] !== 'approved') throw new \RuntimeException("dataset #{$datasetId} is not rights-approved ({$d['rights_status']})");
        if (in_array($d['category'] ?? '', ['evaluation', 'reference', 'speech'], true)) {
            throw new \RuntimeException("dataset #{$datasetId} is {$d['category']} data, not for text training");
        }
        $frozen = ! empty($d['manifest_hash']);
        $fh = ['train' => fopen($dir . 'train.jsonl', 'wb'), 'dev' => fopen($dir . 'dev.jsonl', 'wb')];
        $n  = ['train' => 0, 'dev' => 0]; $bad = 0; $seen = [];
        $q  = $this->db->table('dataset_items')->select('id, instruction, input, output, hash, split')->where('dataset_id', $datasetId);
        if ($frozen) $q->whereIn('split', ['train', 'dev']);                    // the locked test split stays here
        $last = 0;
        while (true) {
            $rows = (clone $q)->where('id >', $last)->orderBy('id')->limit(2000)->get()->getResultArray();
            if (! $rows) break;
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $ins = trim((string) $r['instruction']); $out = trim((string) $r['output']);
                if ($ins === '' || $out === '' || mb_strlen($out) > 8000 || isset($seen[$r['hash']])) { $bad++; continue; }
                $seen[$r['hash']] = true;
                $split = $frozen ? $r['split'] : (hexdec(substr($r['hash'], 0, 4)) % 20 === 0 ? 'dev' : 'train');
                if ($split === 'train' && $maxRows > 0 && $n['train'] >= $maxRows) continue;
                fwrite($fh[$split], json_encode(['instruction' => $ins, 'input' => (string) ($r['input'] ?? ''), 'output' => $out], JSON_UNESCAPED_UNICODE) . "\n");
                $n[$split]++;
            }
        }
        fclose($fh['train']); fclose($fh['dev']);
        if ($n['train'] < $minRows) throw new \RuntimeException("only {$n['train']} usable training rows (the pipeline needs at least {$minRows})");
        if ($n['dev'] < 1) throw new \RuntimeException('no dev rows to check training against');
        return sprintf('dataset #%d: %s train / %s dev rows%s%s', $datasetId, number_format($n['train']), number_format($n['dev']),
            $frozen ? ' (frozen splits; test held back)' : ' (95/5 split)', $bad ? ", {$bad} empty/oversized/duplicate rows skipped" : '');
    }

    // ------------------------------------------------------------------ node agent

    /** Heartbeat from a node agent. Returns what the agent should do. */
    public function heartbeat(array $server, array $in): array
    {
        $now   = date('Y-m-d H:i:s');
        $busy  = (int) ($in['job_id'] ?? 0) ?: null;
        // The agent tells us how a job's process ended; a run that died without reporting fails (or retries) now.
        if (($exited = (int) ($in['exited_job_id'] ?? 0)) > 0) {
            $j = $this->db->table('training_jobs')->where(['id' => $exited, 'server_id' => $server['id']])->get()->getRowArray();
            if ($j && $j['status'] === 'running') {
                $code = (int) ($in['exit_code'] ?? -1);
                $this->fail($exited, "The training process on {$server['name']} exited with code {$code} without reporting a result. " . mb_substr((string) ($in['tail'] ?? ''), -500), true);
            }
        }
        $gpus = (int) ($in['gpus'] ?? 0);                                    // the agent counts what nvidia-smi sees
        if ($gpus > 0 && $gpus !== (int) ($server['gpu_count'] ?? 0)) {
            $this->db->table('servers')->where('id', $server['id'])->update(['gpu_count' => $gpus]);
        }
        $this->db->table('servers')->where('id', $server['id'])->update([
            'agent_seen_at' => $now, 'agent_version' => mb_substr((string) ($in['version'] ?? ''), 0, 20) ?: null, 'agent_job_id' => $busy,
        ]);
        if (! empty($in['probe'])) return ['action' => 'idle', 'why' => 'probe'];   // the installer's token check never takes work
        if ($busy) {
            $job = $this->db->table('training_jobs')->where('id', $busy)->get()->getRowArray();
            if (! $job || (int) $job['cancel_requested'] === 1 || in_array($job['status'], ['cancelled', 'failed'], true)) return ['action' => 'stop', 'job_id' => $busy];
            // the process is alive on the node: that counts as a sign of life (first-run setup can be long and quiet)
            if ($job['status'] === 'running') $this->db->table('training_jobs')->where('id', $busy)->update(['last_report_at' => $now]);
            return ['action' => 'continue', 'job_id' => $busy];
        }
        if (! (int) ($server['is_enabled'] ?? 1) || ! (int) ($server['allow_training'] ?? 0)) return ['action' => 'idle', 'why' => 'training is off for this node'];
        $job = $this->claim($server);
        return $job ?: ['action' => 'idle'];
    }

    private function claim(array $server): ?array
    {
        $queued = $this->db->table('training_jobs')->where('status', 'queued')
            ->groupStart()->where('server_id', null)->orWhere('server_id', $server['id'])->groupEnd()
            ->orderBy('id')->get()->getResultArray();
        foreach ($queued as $job) {
            $recipe = TrainingRecipes::get((string) $job['recipe']);
            if (! $recipe || ! TrainingRecipes::fits($recipe, $server)) continue;
            $token = 'aist_' . bin2hex(random_bytes(24));
            $now   = date('Y-m-d H:i:s');
            $this->db->table('training_jobs')->where(['id' => $job['id'], 'status' => 'queued'])->update([
                'status' => 'running', 'phase' => 'starting on ' . $server['name'], 'server_id' => $server['id'], 'claimed_at' => $now,
                'started_at' => $now, 'last_report_at' => $now, 'report_token_hash' => hash('sha256', $token), 'attempt' => (int) $job['attempt'] + 1,
                'hardware' => trim(($server['gpu_model'] ?? '') . ' · ' . $server['name'], ' ·'), 'error' => null,
            ]);
            if ($this->db->affectedRows() !== 1) continue;                     // another node took it
            $base = rtrim((string) config('App')->baseURL, '/');
            $this->notify("Training started: {$job['name']}", "Job #{$job['id']} on {$server['name']} (attempt " . ((int) $job['attempt'] + 1) . ').', 'info');
            return ['action' => 'run', 'job_id' => (int) $job['id'], 'token' => $token,
                    'job_url' => "{$base}/api/training/{$job['id']}/progress", 'runner_url' => "{$base}/node/aistudio-train-runner.sh"];
        }
        return null;
    }

    // ------------------------------------------------------------------ stalls, cancel, retry

    private function reap(): array
    {
        $out = [];
        $now = time();
        foreach ($this->db->table('training_jobs')->where('status', 'running')->where('recipe IS NOT NULL')->get()->getResultArray() as $j) {
            $last = strtotime((string) ($j['last_report_at'] ?: $j['claimed_at'] ?: $j['started_at']));
            if ($now - $last < self::STALL_SECONDS) continue;
            $msg = 'No progress report for ' . round(($now - $last) / 60) . ' minutes (node offline or the run crashed).';
            $out[] = $this->fail((int) $j['id'], $msg, true);
        }
        return $out;
    }

    /** Fail a job; with $retry and attempts left it goes back to the queue instead. Returns the new status. */
    public function fail(int $id, string $error, bool $retry): string
    {
        $j = $this->db->table('training_jobs')->where('id', $id)->get()->getRowArray();
        if (! $j) return 'missing';
        $again = $retry && (int) $j['attempt'] < (int) $j['max_attempts'] && ! (int) $j['cancel_requested'];
        $this->db->table('training_jobs')->where('id', $id)->update([
            'status' => $again ? 'queued' : 'failed', 'phase' => $again ? 'waiting for a node (retry)' : 'failed',
            'error' => $error, 'finished_at' => $again ? null : date('Y-m-d H:i:s'), 'report_token_hash' => $again ? null : $j['report_token_hash'],
            'logs' => ($j['logs'] ?? '') . date('Y-m-d H:i:s') . ' ' . $error . ($again ? ' Retrying.' : '') . "\n",
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->notify(($again ? 'Training retrying: ' : 'Training failed: ') . $j['name'], "Job #{$id}: {$error}", $again ? 'warning' : 'error', ! $again);
        return $again ? 'queued' : 'failed';
    }

    public function cancel(int $id): void
    {
        $j = $this->db->table('training_jobs')->where('id', $id)->get()->getRowArray();
        if (! $j) throw new \InvalidArgumentException('Job not found.');
        $was = $j['status'];
        $upd = ['cancel_requested' => 1, 'updated_at' => date('Y-m-d H:i:s')];
        if (in_array($was, ['preparing', 'queued', 'running'], true)) {
            $upd += ['status' => 'cancelled', 'phase' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s')];
        }
        $this->db->table('training_jobs')->where('id', $id)->update($upd);
        $this->notify("Training cancelled: {$j['name']}", "Job #{$id} was cancelled" . ($was === 'running' ? '; the node stops it within a minute.' : '.'), 'warning');
    }

    public function retry(int $id): void
    {
        $j = $this->db->table('training_jobs')->where('id', $id)->get()->getRowArray();
        if (! $j || ! $j['recipe']) throw new \InvalidArgumentException('Only pipeline jobs can be retried here.');
        if (! in_array($j['status'], ['failed', 'cancelled'], true)) throw new \RuntimeException('Only a failed or cancelled job can be retried.');
        if (! is_file(WRITEPATH . 'training/' . $id . '/files/manifest.txt')) throw new \RuntimeException('Its staged files are gone; run the pipeline again instead.');
        $this->db->table('training_jobs')->where('id', $id)->update([
            'status' => 'queued', 'phase' => 'waiting for a node (manual retry)', 'cancel_requested' => 0, 'error' => null,
            'attempt' => 0, 'finished_at' => null, 'report_token_hash' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Called when a runner reports completed/failed: notify. */
    public function finished(array $job, string $status): void
    {
        if (! $job['recipe']) return;
        if ($status === 'completed') {
            $this->notify("Training completed: {$job['name']}", "Job #{$job['id']} finished; its result is on the server (Training → job → Results).", 'success', true);
        } elseif ($status === 'failed') {
            $this->notify("Training failed: {$job['name']}", "Job #{$job['id']} reported a failure. See its log on the Training page.", 'error', true);
        }
    }

    private function notify(string $title, string $body, string $level, bool $external = false): void
    {
        try {
            $this->notes->notify(null, $title, $body, $level, 'training');
            if ($external) $this->notes->dispatch($title, $body);              // Slack / Discord / Telegram / email / webhook, if set
        } catch (\Throwable) {
            // a notification must never break the pipeline
        }
    }
}
