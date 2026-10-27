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
}
