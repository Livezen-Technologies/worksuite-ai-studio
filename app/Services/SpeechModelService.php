<?php

namespace App\Services;

/**
 * Custom speech models (recipe whisper_custom): convert the uploaded Whisper to CTranslate2 int8 and add it to the
 * speech catalog (/srv/aistudio-models/asr/catalog.json). The speech service on this server reads the catalog on every
 * request, and GPU nodes receive the model and the catalog with their next sync (within 30 minutes).
 */
class SpeechModelService
{
    public const ROOT    = '/srv/aistudio-models/asr';
    public const CATALOG = self::ROOT . '/catalog.json';

    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** @return array<string, array{label:string, dir:string, note:string, job_id:int}> */
    public static function catalog(): array
    {
        return json_decode((string) @file_get_contents(self::CATALOG), true) ?: [];
    }

    public function install(int $jobId): array
    {
        $job = $this->db->table('training_jobs')->where('id', $jobId)->get()->getRowArray();
        if (! $job) throw new \RuntimeException("Job #{$jobId} not found.");
        $p   = $job['pipeline_id'] ? $this->db->table('training_pipelines')->where('id', $job['pipeline_id'])->get()->getRowArray() : null;
        $out = json_decode((string) ($p['output'] ?? ''), true) ?: [];
        $label = trim((string) ($out['label'] ?? '')) ?: ($p['name'] ?? $job['name']);
        $key   = trim((string) ($out['name'] ?? '')) ?: LlmFinalizeService::slug($label);
        $tar = WRITEPATH . 'training/' . $jobId . '/output/whisper-custom.tar.gz';
        if (! is_file($tar)) throw new \RuntimeException('No speech model was uploaded for this job.');

        $work = self::ROOT . "/work-job{$jobId}";
        $dir  = self::ROOT . '/' . $key . '-job' . $jobId;
        exec('rm -rf ' . escapeshellarg($work) . ' && mkdir -p ' . escapeshellarg($work) . ' && tar xzf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($work) . ' 2>&1', $o, $rc);
        if ($rc !== 0) throw new \RuntimeException('Unpacking the model failed: ' . implode(' ', $o));
        $hf = $work . '/whisper-custom';
        $metrics = json_decode((string) @file_get_contents($hf . '/metrics.json'), true) ?: [];
        $o = [];
        exec(escapeshellarg(LlmFinalizeService::TOOLS . '/venv/bin/ct2-transformers-converter') . ' --model ' . escapeshellarg($hf) . ' --output_dir ' . escapeshellarg($dir)
            . ' --quantization int8 --copy_files tokenizer_config.json preprocessor_config.json --force 2>&1', $o, $rc);
        if ($rc !== 0 || ! is_file($dir . '/model.bin')) throw new \RuntimeException('Converting for the speech service failed: ' . implode(' ', array_slice($o, -3)));
        if (is_file($hf . '/tokenizer.json')) copy($hf . '/tokenizer.json', $dir . '/tokenizer.json');
        exec('rm -rf ' . escapeshellarg($work));

        $yours = $metrics['after']['yours'] ?? null; $y0 = $metrics['before']['yours'] ?? null;
        $gen = $metrics['after']['general'] ?? null; $g0 = $metrics['before']['general'] ?? null;
        $note = 'Custom: trained on your recordings (job #' . $jobId . ')'
            . ($yours && $y0 ? sprintf('. Your recordings WER %.1f%% → %.1f%%', $y0['wer'] * 100, $yours['wer'] * 100) : '')
            . ($gen && $g0 ? sprintf(', general Sinhala %.1f%% → %.1f%%', $g0['wer'] * 100, $gen['wer'] * 100) : '') . '.';
        $cat = self::catalog();
        $cat[$key] = ['label' => $label, 'dir' => $dir, 'note' => $note, 'job_id' => $jobId, 'language' => $metrics['language'] ?? 'si'];
        file_put_contents(self::CATALOG . '.tmp', json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename(self::CATALOG . '.tmp', self::CATALOG);

        $now = date('Y-m-d H:i:s');
        $this->db->table('training_jobs')->where('id', $jobId)->update([
            'output_status' => 'ready', 'output_model' => $key, 'output_path' => $dir, 'phase' => 'speech model ready', 'updated_at' => $now,
        ]);
        (new NotificationService())->notify(null, "Speech model ready: {$label}", "{$note} Choose it on the Speech page (Model).", 'success', 'training');
        return ['key' => $key, 'dir' => $dir, 'note' => $note];
    }
}
