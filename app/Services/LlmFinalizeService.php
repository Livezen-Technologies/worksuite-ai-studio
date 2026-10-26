<?php

namespace App\Services;

/**
 * Custom LLM finalisation, on this server after a node uploaded a LoRA adapter (recipe text_sft):
 *   merge into the base → GGUF f16 → quantize → `ollama create <name>` → the local node's model list → an Engine package.
 * Runs as `spark training:finalize <job>` in the background (minutes of CPU work), with the shared toolkit in
 * /opt/aistudio-llm; progress goes to the job's phase and log, the result to Notifications.
 */
class LlmFinalizeService
{
    public const TOOLS  = '/opt/aistudio-llm';
    public const MODELS = '/srv/aistudio-models';
    public const HF     = '/srv/aistudio-hf';

    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** Start finalisation in the background (called when a text_sft job completes). */
    public static function spawn(int $jobId): void
    {
        $cmd = sprintf('cd %s && setsid nohup php8.3 spark training:finalize %d > %s 2>&1 < /dev/null &',
            escapeshellarg(ROOTPATH), $jobId, escapeshellarg(WRITEPATH . 'training/' . $jobId . '/finalize.log'));
        \Config\Database::connect()->table('training_jobs')->where('id', $jobId)->update(['output_status' => 'building', 'phase' => 'building the model']);
        exec($cmd);
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        return $s !== '' ? substr($s, 0, 60) : 'custom-llm';
    }

    public function run(int $jobId): array
    {
        $job = $this->db->table('training_jobs')->where('id', $jobId)->get()->getRowArray();
        if (! $job) throw new \RuntimeException("Job #{$jobId} not found.");
        $p   = $job['pipeline_id'] ? $this->db->table('training_pipelines')->where('id', $job['pipeline_id'])->get()->getRowArray() : null;
        $out = json_decode((string) ($p['output'] ?? ''), true) ?: [];
        $name  = trim((string) ($out['ollama_name'] ?? '')) ?: self::slug($p['name'] ?? $job['name']) . ':latest';
        $quant = strtoupper((string) ($out['quant'] ?? 'Q4_K_M'));
        $adapter = WRITEPATH . 'training/' . $jobId . '/output/adapter.tar.gz';
        if (! is_file($adapter)) throw new \RuntimeException('No adapter was uploaded for this job.');
        $dir = self::MODELS . '/' . self::slug($name) . '-job' . $jobId;

        $this->log($jobId, "Building {$name} from {$job['base_model']} + the job's adapter ({$quant}).", 'merging the adapter');
        $env = 'HF_HOME=' . escapeshellarg(self::HF) . ' OMP_NUM_THREADS=6 nice -n 10 ';
        $cmd = $env . escapeshellarg(self::TOOLS . '/venv/bin/python') . ' ' . escapeshellarg(ROOTPATH . 'deploy/train/finalize_llm.py')
            . ' --adapter ' . escapeshellarg($adapter) . ' --base ' . escapeshellarg($job['base_model'])
            . ' --out ' . escapeshellarg($dir) . ' --quant ' . escapeshellarg($quant) . ' 2>&1';
        $result = null; $tail = [];
        $ph = popen($cmd, 'r');
        while (($line = fgets($ph)) !== false) {
            $line = rtrim($line);
            if (str_starts_with($line, 'PHASE: ')) $this->log($jobId, $line, substr($line, 7));
            elseif (str_starts_with($line, 'RESULT ')) $result = json_decode(substr($line, 7), true);
            elseif ($line !== '') { $tail[] = $line; $tail = array_slice($tail, -15); }
        }
        $code = pclose($ph);
        if ($code !== 0 || ! $result) {
            throw new \RuntimeException('Building the model failed: ' . implode(' | ', array_slice($tail, -4)));
        }

        // Ollama: the GGUF plus the pipeline's system prompt.
        $system = trim((string) ($out['system_prompt'] ?? ''));
        $modelfile = "FROM {$result['gguf']}\n" . ($system !== '' ? 'SYSTEM """' . str_replace('"""', '"', $system) . "\"\"\"\n" : '')
            . "PARAMETER temperature 0.7\n";
        file_put_contents($dir . '/Modelfile', $modelfile);
        $this->log($jobId, 'Adding to Ollama as ' . $name . '.', 'adding to Ollama');
        exec('ollama create ' . escapeshellarg($name) . ' -f ' . escapeshellarg($dir . '/Modelfile') . ' 2>&1', $o, $rc);
        if ($rc !== 0) throw new \RuntimeException('ollama create failed: ' . implode(' ', array_slice($o, -3)));

        foreach ($this->db->table('servers')->where('is_local', 1)->where('deleted_at', null)->get()->getResultArray() as $s) {
            try { (new ModelService())->syncServer($s); } catch (\Throwable) { /* the hourly sync catches up */ }
        }
        $this->package($job, $name, $result);
        $this->db->table('training_jobs')->where('id', $jobId)->update([
            'output_status' => 'ready', 'output_model' => $name, 'output_path' => $result['gguf'], 'output_digest' => $result['sha256'],
            'phase' => 'model ready', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->log($jobId, sprintf('Model ready: %s (%s, %.1f GB, built in %.1f min).', $name, $quant, $result['size_bytes'] / 1073741824, $result['minutes']));
        (new NotificationService())->notify(null, "Custom LLM ready: {$name}", "Job #{$jobId} — it is in Models and in Chat.", 'success', 'training');
        return $result + ['name' => $name];
    }

    public function fail(int $jobId, string $why): void
    {
        $this->db->table('training_jobs')->where('id', $jobId)->update(['output_status' => 'failed', 'phase' => 'model build failed', 'error' => mb_substr($why, 0, 2000)]);
        $this->log($jobId, $why);
        (new NotificationService())->notify(null, 'Custom LLM build failed', "Job #{$jobId}: {$why}", 'error', 'training');
    }

    /** Register the GGUF as an Engine text package (downloadable with an API key), hard-linked into the store. */
    private function package(array $job, string $name, array $r): void
    {
        $svc = new EnginePackageService();
        $now = date('Y-m-d H:i:s');
        $row = [
            'package_id' => $this->uuid(), 'version' => '1.0.0', 'task' => 'text', 'languages' => 'si', 'runtime_name' => 'ollama',
            'runtime_range' => '>=0.5', 'base_model' => "{$job['base_model']} + LoRA (training job #{$job['id']}) — {$name}",
            'quantization' => $r['quant'], 'license_id' => 'Inherits the base model and dataset licences',
            'artifacts' => json_encode([['path' => basename($r['gguf']), 'sha256' => $r['sha256'], 'size_bytes' => $r['size_bytes'], 'server_verified' => true]]),
            'training_job_id' => $job['id'], 'min_ram_gib' => max(2, (int) ceil($r['size_bytes'] / 1073741824) + 1), 'min_disk_gib' => round($r['size_bytes'] / 1073741824 * 1.2, 2),
            'state' => 'candidate', 'state_history' => json_encode([['from' => null, 'to' => 'candidate', 'at' => $now, 'by' => null, 'actor' => null, 'reason' => "Built from training job #{$job['id']}"]]),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->db->table('engine_packages')->insert($row);
        $pkg = $this->db->table('engine_packages')->where('id', $this->db->insertID())->get()->getRowArray();
        $dst = $svc->artifactDir($pkg);
        @mkdir($dst, 0755, true);
        @link($r['gguf'], $dst . basename($r['gguf'])) || @copy($r['gguf'], $dst . basename($r['gguf']));
    }

    private function log(int $jobId, string $line, ?string $phase = null): void
    {
        $j = $this->db->table('training_jobs')->select('logs')->where('id', $jobId)->get()->getRowArray();
        $upd = ['logs' => ($j['logs'] ?? '') . date('H:i:s') . ' ' . $line . "\n", 'updated_at' => date('Y-m-d H:i:s')];
        if ($phase) $upd['phase'] = mb_substr($phase, 0, 40);
        $this->db->table('training_jobs')->where('id', $jobId)->update($upd);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
