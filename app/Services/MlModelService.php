<?php

namespace App\Services;

/**
 * ML models served by the Studio's ML service (127.0.0.1:8097, `aistudio-ml`): installing a trained classifier from a
 * finished job, and asking the service for predictions.
 */
class MlModelService
{
    public const ROOT = '/srv/aistudio-models/ml';

    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    private function url(): string
    {
        return rtrim((string) (env('ML_SERVICE_URL') ?: 'http://127.0.0.1:8097'), '/');
    }

    /** Unpack a text_classifier job's upload and register it (called by `spark training:finalize`). */
    public function installClassifier(int $jobId): array
    {
        $job = $this->db->table('training_jobs')->where('id', $jobId)->get()->getRowArray();
        if (! $job) throw new \RuntimeException("Job #{$jobId} not found.");
        $p    = $job['pipeline_id'] ? $this->db->table('training_pipelines')->where('id', $job['pipeline_id'])->get()->getRowArray() : null;
        $out  = json_decode((string) ($p['output'] ?? ''), true) ?: [];
        $name = trim((string) ($out['name'] ?? '')) ?: LlmFinalizeService::slug($p['name'] ?? $job['name']);
        $tar  = WRITEPATH . 'training/' . $jobId . '/output/classifier.tar.gz';
        if (! is_file($tar)) throw new \RuntimeException('No classifier was uploaded for this job.');

        $dir = self::ROOT . '/' . LlmFinalizeService::slug($name) . '-job' . $jobId;
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) throw new \RuntimeException("Cannot create {$dir}.");
        exec('tar xzf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($dir) . ' --strip-components=1 2>&1', $o, $rc);
        if ($rc !== 0 || ! is_file($dir . '/config.json')) throw new \RuntimeException('The classifier archive is incomplete: ' . implode(' ', $o));
        $labels  = json_decode((string) @file_get_contents($dir . '/labels.json'), true) ?: [];
        $metrics = json_decode((string) @file_get_contents($dir . '/metrics.json'), true) ?: [];

        // The newest model takes the name; an older model with the same name keeps its files but steps aside.
        $now = date('Y-m-d H:i:s');
        $old = $this->db->table('ml_models')->where('name', $name)->get()->getRowArray();
        if ($old) $this->db->table('ml_models')->where('id', $old['id'])->update(['name' => $name . '@job' . ($old['job_id'] ?: $old['id']), 'status' => 'replaced', 'updated_at' => $now]);
        $this->db->table('ml_models')->insert([
            'name' => $name, 'kind' => 'classifier', 'job_id' => $jobId, 'pipeline_id' => $job['pipeline_id'], 'dataset_id' => $job['dataset_id'],
            'base_model' => $job['base_model'], 'path' => $dir, 'labels' => json_encode($labels, JSON_UNESCAPED_UNICODE),
            'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE), 'status' => 'ready', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('training_jobs')->where('id', $jobId)->update([
            'output_status' => 'ready', 'output_model' => $name, 'output_path' => $dir, 'phase' => 'classifier ready', 'updated_at' => $now,
        ]);
        $acc = isset($metrics['accuracy']) ? sprintf(' — accuracy %.1f%%, macro-F1 %.1f%% on the dev rows', $metrics['accuracy'] * 100, ($metrics['macro_f1'] ?? 0) * 100) : '';
        (new NotificationService())->notify(null, "Classifier ready: {$name}", "Job #{$jobId}, " . count($labels) . " labels{$acc}. Try it on the Training page or call POST /api/v1/classify.", 'success', 'training');
        return ['name' => $name, 'path' => $dir, 'labels' => $labels, 'metrics' => $metrics];
    }

    /** Predict: one or more texts → [{label, score, scores}] (top $top labels per text). */
    public function classify(array $model, array $texts, int $top = 3): array
    {
        $texts = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 20000), $texts), fn ($t) => $t !== ''));
        if (! $texts) throw new \InvalidArgumentException('Give some text to classify.');
        if (count($texts) > 256) throw new \InvalidArgumentException('Up to 256 texts per request.');
        $ch = curl_init($this->url() . '/classify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['path' => $model['path'], 'texts' => $texts, 'top' => max(1, min(20, $top))], JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body === false || $code === 0) throw new \RuntimeException('The ML service is not running (aistudio-ml).');
        $j = json_decode((string) $body, true) ?: [];
        if ($code !== 200) throw new \RuntimeException($j['error'] ?? 'Prediction failed.');
        return $j['results'] ?? [];
    }

    // ------------------------------------------------------------------ table models (part 4)

    /** Register a table model and start training it in the background on this server. */
    public function createTable(string $name, string $csv, string $target, string $task, ?int $userId): array
    {
        $name = LlmFinalizeService::slug($name);
        if ($name === '') throw new \InvalidArgumentException('Give the model a name.');
        if (! in_array($task, ['auto', 'classification', 'regression'], true)) throw new \InvalidArgumentException('Unknown task.');
        if (trim($target) === '') throw new \InvalidArgumentException('Choose the column to predict.');
        if ($this->db->table('ml_models')->where('requested_name', $name)->where('status', 'training')->countAllResults()) {
            throw new \RuntimeException("A model called {$name} is already training.");
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('ml_models')->insert([
            'name' => $name . '~' . bin2hex(random_bytes(3)), 'requested_name' => $name, 'kind' => 'table', 'task' => $task === 'auto' ? null : $task,
            'target' => mb_substr($target, 0, 191), 'base_model' => 'scikit-learn', 'path' => '', 'status' => 'training', 'progress' => 'queued',
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        $dir = self::ROOT . "/{$name}-t{$id}";
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) throw new \RuntimeException("Cannot create {$dir}.");
        if (! @rename($csv, "{$dir}/data.csv") && ! copy($csv, "{$dir}/data.csv")) throw new \RuntimeException('Could not keep the uploaded file.');
        $this->db->table('ml_models')->where('id', $id)->update(['path' => $dir]);
        exec(sprintf('cd %s && setsid nohup php8.3 spark ml:train-table %d %s > %s 2>&1 < /dev/null &',
            escapeshellarg(ROOTPATH), $id, escapeshellarg($task), escapeshellarg(WRITEPATH . "logs/ml-table-{$id}.log")));
        return $this->db->table('ml_models')->where('id', $id)->get()->getRowArray();
    }

    /** The detached trainer: runs deploy/ml/train_table.py with the shared toolkit, records progress and the result. */
    public function trainTable(int $id, string $task): void
    {
        $m = $this->db->table('ml_models')->where('id', $id)->get()->getRowArray();
        if (! $m || $m['status'] !== 'training') return;
        $py = '/opt/aistudio-llm/venv/bin/python';
        $cmd = sprintf('nice -n 10 %s -u %s %s %s %s %s 2>&1', $py, escapeshellarg(ROOTPATH . 'deploy/ml/train_table.py'),
            escapeshellarg($m['path'] . '/data.csv'), escapeshellarg($m['path']), escapeshellarg($m['target']), escapeshellarg($task));
        $h = popen($cmd, 'r'); $last = '';
        while (($line = fgets($h)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $last = $line;
            $j = json_decode($line, true);
            if (is_array($j) && isset($j['phase'])) {
                $p = $j['phase'] . (isset($j['model']) ? ': ' . $j['model'] : '') . (isset($j['score']) ? ' (' . $j['score'] . ')' : '') . (isset($j['rows']) ? " — {$j['rows']} rows" : '');
                $this->db->table('ml_models')->where('id', $id)->update(['progress' => mb_substr($p, 0, 255), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        $rc = pclose($h);
        $metrics = json_decode((string) @file_get_contents($m['path'] . '/metrics.json'), true);
        $schema  = json_decode((string) @file_get_contents($m['path'] . '/schema.json'), true);
        $now = date('Y-m-d H:i:s');
        if ($rc !== 0 || ! $metrics || ! $schema || ! is_file($m['path'] . '/model.joblib')) {
            $err = json_decode($last, true) === null ? $last : 'The trainer stopped without a result.';
            $this->db->table('ml_models')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($err ?: 'The trainer stopped without a result.', 0, 500), 'updated_at' => $now]);
            (new NotificationService())->notify(null, "Table model failed: {$m['requested_name']}", (string) $err, 'error', 'training');
            return;
        }
        $name = $m['requested_name'];
        $old = $this->db->table('ml_models')->where('name', $name)->get()->getRowArray();
        if ($old) $this->db->table('ml_models')->where('id', $old['id'])->update(['name' => $name . '@' . $old['id'], 'status' => 'replaced', 'updated_at' => $now]);
        $this->db->table('ml_models')->where('id', $id)->update([
            'name' => $name, 'status' => 'ready', 'task' => $metrics['task'], 'progress' => null, 'base_model' => 'scikit-learn · ' . $metrics['model'],
            'labels' => json_encode($metrics['labels'] ?? [], JSON_UNESCAPED_UNICODE), 'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE), 'updated_at' => $now,
        ]);
        @unlink($m['path'] . '/data.csv');   // the model is what is kept; the upload is not needed any more
        $q = $metrics['task'] === 'classification' ? sprintf('accuracy %.1f%%, macro-F1 %.1f%%', $metrics['accuracy'] * 100, $metrics['macro_f1'] * 100)
            : sprintf('R² %.3f, typical error %s', $metrics['r2'], $metrics['mae']);
        (new NotificationService())->notify(null, "Table model ready: {$name}", "{$metrics['model']} predicts {$m['target']} — {$q} on rows it never saw.", 'success', 'training');
    }

    /** Predict with a table model: rows of {column: value} → [{value, score?, scores?}]. */
    public function predictTable(array $model, array $rows, int $top = 3): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if (! $rows) throw new \InvalidArgumentException('Give at least one row: {"column": value, …}.');
        if (count($rows) > 1000) throw new \InvalidArgumentException('Up to 1000 rows per request.');
        $ch = curl_init($this->url() . '/predict');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['path' => $model['path'], 'rows' => $rows, 'top' => max(1, min(20, $top))], JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body === false || $code === 0) throw new \RuntimeException('The ML service is not running (aistudio-ml).');
        $j = json_decode((string) $body, true) ?: [];
        if ($code !== 200) throw new \RuntimeException($j['error'] ?? 'Prediction failed.');
        return $j['results'] ?? [];
    }
}
