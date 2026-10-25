<?php

namespace App\Commands;

use App\Services\EnginePackageService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark engine:register-local [--dry-run]` — register the Local models set (Whisper sizes, Real-ESRGAN, RIFE, MODNet)
 * as Engine candidate packages from the files already on this server. Every artifact is hashed and measured here
 * (server verified); a model already registered at the same version is skipped. Lifecycle moves past candidate
 * — verification, evaluation, approval, signing — stay with people on the Engine page.
 */
class EngineRegisterLocal extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'engine:register-local';
    protected $description = 'Register the Local models set (Whisper sizes + vision) as Engine candidate packages.';
    protected $usage       = 'engine:register-local [--dry-run]';

    private const ROOT = '/srv/sinhala-data/models';

    /** dir, files (glob, relative to dir), package fields */
    private const SET = [
        ['whisper-small-sinhala-ct2-int8', ['*'], ['task' => 'asr', 'runtime_name' => 'faster-whisper', 'runtime_range' => '>=1.0,<2.0', 'languages' => 'si', 'base_model' => 'openai/whisper-small > SinhaSpeech/whisper-small-sinhala-run11', 'quantization' => 'int8', 'license_id' => 'Apache-2.0', 'min_ram_gib' => 1, 'note' => 'Whisper Sinhala (package #1).']],
        ['faster-whisper-tiny', ['*'], ['task' => 'asr', 'runtime_name' => 'faster-whisper', 'runtime_range' => '>=1.0,<2.0', 'languages' => 'multi', 'base_model' => 'openai/whisper-tiny (Systran CTranslate2)', 'quantization' => 'fp16', 'license_id' => 'MIT', 'min_ram_gib' => 1, 'note' => 'Whisper Tiny — fastest, lower accuracy.']],
        ['faster-whisper-base', ['*'], ['task' => 'asr', 'runtime_name' => 'faster-whisper', 'runtime_range' => '>=1.0,<2.0', 'languages' => 'multi', 'base_model' => 'openai/whisper-base (Systran CTranslate2)', 'quantization' => 'fp16', 'license_id' => 'MIT', 'min_ram_gib' => 1, 'note' => 'Whisper Base — language identification for mixed speech.']],
        ['faster-whisper-small', ['*'], ['task' => 'asr', 'runtime_name' => 'faster-whisper', 'runtime_range' => '>=1.0,<2.0', 'languages' => 'multi', 'base_model' => 'openai/whisper-small (Systran CTranslate2)', 'quantization' => 'fp16', 'license_id' => 'MIT', 'min_ram_gib' => 2, 'note' => 'Whisper Small — good balance; English phrases in mixed speech.']],
        ['faster-whisper-medium', ['*'], ['task' => 'asr', 'runtime_name' => 'faster-whisper', 'runtime_range' => '>=1.0,<2.0', 'languages' => 'multi', 'base_model' => 'openai/whisper-medium (Systran CTranslate2)', 'quantization' => 'fp16', 'license_id' => 'MIT', 'min_ram_gib' => 4, 'note' => 'Whisper Medium — higher accuracy, slower.']],
        ['faster-whisper-large-v3-turbo', ['*'], ['task' => 'asr', 'runtime_name' => 'faster-whisper', 'runtime_range' => '>=1.0,<2.0', 'languages' => 'multi', 'base_model' => 'openai/whisper-large-v3-turbo (deepdml CTranslate2)', 'quantization' => 'fp16', 'license_id' => 'MIT', 'min_ram_gib' => 6, 'note' => 'Whisper Large v3 Turbo — best multilingual accuracy.']],
        ['vision/real-esrgan-x4plus', ['RealESRGAN_x4plus.pth'], ['task' => 'image_upscale', 'runtime_name' => 'pytorch', 'runtime_range' => '>=1.7,<3.0', 'languages' => 'none', 'base_model' => 'xinntao/Real-ESRGAN RealESRGAN_x4plus (v0.1.0 release)', 'quantization' => 'fp32', 'license_id' => 'BSD-3-Clause', 'min_ram_gib' => 2, 'note' => 'AI Ultra HD: restores detail and removes compression artefacts.']],
        ['vision/rife', ['*.onnx'], ['task' => 'frame_interpolation', 'runtime_name' => 'onnxruntime', 'runtime_range' => '>=1.16,<2.0', 'languages' => 'none', 'base_model' => 'hzwer/Practical-RIFE 4.7–4.9 (ONNX)', 'quantization' => 'fp32', 'license_id' => 'MIT', 'min_ram_gib' => 1, 'note' => 'Optical-flow in-between frames for smoother motion.']],
        ['vision/modnet', ['onnx/model.onnx', 'onnx/model_quantized.onnx', 'config.json', 'preprocessor_config.json'], ['task' => 'background_removal', 'runtime_name' => 'onnxruntime', 'runtime_range' => '>=1.16,<2.0', 'languages' => 'none', 'base_model' => 'ZHKKKe/MODNet (Xenova ONNX)', 'quantization' => 'fp32 + int8', 'license_id' => 'Apache-2.0', 'min_ram_gib' => 1, 'note' => 'Cuts people out of photos without a green screen.']],
        // Sinhala chat models as GGUF (the files Ollama was built from). Licences as stated on each model card.
        ['.', ['helalm-4b.Q4_K_M.gguf'], ['task' => 'text', 'runtime_name' => 'ollama', 'runtime_range' => '>=0.5', 'languages' => 'si', 'base_model' => 'HelaAI/SinhalaLM-v0.0.1 (helalm:4b)', 'quantization' => 'Q4_K_M', 'license_id' => 'Other — HelaAI terms (see model card)', 'min_ram_gib' => 4, 'note' => 'helalm:4b, the AI Studio default chat model.']],
        ['.', ['llama3-sinhala-8b-q4_k_m.gguf'], ['task' => 'text', 'runtime_name' => 'ollama', 'runtime_range' => '>=0.5', 'languages' => 'si', 'base_model' => 'featherless llama3-sinhala 8B (llama3-sinhala:8b)', 'quantization' => 'Q4_K_M', 'license_id' => 'Apache-2.0 (card); Meta Llama 3 base', 'min_ram_gib' => 8, 'note' => 'llama3-sinhala:8b.']],
        ['.', ['sinllama-8b-q4_k_m.gguf'], ['task' => 'text', 'runtime_name' => 'ollama', 'runtime_range' => '>=0.5', 'languages' => 'si', 'base_model' => 'SinLlama v01 8B (sinllama:8b)', 'quantization' => 'Q4_K_M', 'license_id' => 'Meta Llama 3 Community License', 'min_ram_gib' => 8, 'note' => 'sinllama:8b.']],
        ['.', ['sinhalallm-1.7b-q8_0.gguf'], ['task' => 'text', 'runtime_name' => 'ollama', 'runtime_range' => '>=0.5', 'languages' => 'si', 'base_model' => 'namindu94/SinhalaLLM-1.7B (sinhalallm:1.7b)', 'quantization' => 'Q8_0', 'license_id' => 'Not stated on the model card', 'min_ram_gib' => 3, 'note' => 'sinhalallm:1.7b — licence unknown, check before any use.']],
        ['.', ['sinhala-llama-1b-sft-diverse-q8_0.gguf'], ['task' => 'text', 'runtime_name' => 'ollama', 'runtime_range' => '>=0.5', 'languages' => 'si', 'base_model' => 'sinhala-llama-1b-sft diverse (sinhala-llama-1b-sft:diverse)', 'quantization' => 'Q8_0', 'license_id' => 'Llama 3.2 Community License', 'min_ram_gib' => 2, 'note' => 'sinhala-llama-1b-sft:diverse.']],
    ];

    public function run(array $params)
    {
        $db  = \Config\Database::connect();
        $svc = new EnginePackageService();
        $dry = CLI::getOption('dry-run') !== null || in_array('--dry-run', $params, true);
        foreach (self::SET as [$dir, $globs, $f]) {
            $base = $dir === '.' ? self::ROOT : self::ROOT . '/' . $dir;
            $files = [];
            foreach ($globs as $g) {
                foreach (glob($base . '/' . $g) ?: [] as $p) {
                    if (is_file($p) && ! str_ends_with($p, '.md') && ! str_contains($p, '/.')) $files[$p] = true;
                }
            }
            if (! $files) { CLI::write("  {$dir}: no files on this server — skipped.", 'yellow'); continue; }
            if ($existing = $db->table('engine_packages')->where(['base_model' => $f['base_model'], 'version' => '1.0.0'])->get()->getRowArray()) {
                if (! $dry) $this->link($svc, $existing, $base);
                CLI::write("  {$dir} {$f['base_model']}: already registered; files linked for download."); continue;
            }
            $artifacts = [];
            foreach (array_keys($files) as $p) {
                $artifacts[] = ['path' => substr($p, strlen($base) + 1), 'sha256' => hash_file('sha256', $p), 'size_bytes' => filesize($p), 'server_verified' => true];
            }
            if ($errs = $svc->validateArtifacts($artifacts)) { CLI::error("  {$dir}: " . implode(' ', $errs)); continue; }
            $size = array_sum(array_column($artifacts, 'size_bytes'));
            CLI::write(sprintf('  %-32s %-20s %2d files %7.1f MB  %s', $dir, $f['task'], count($artifacts), $size / 1048576, $f['license_id']));
            if ($dry) continue;
            $now = date('Y-m-d H:i:s');
            $db->table('engine_packages')->insert([
                'package_id' => $this->uuid(), 'version' => '1.0.0', 'task' => $f['task'], 'languages' => $f['languages'],
                'runtime_name' => $f['runtime_name'], 'runtime_range' => $f['runtime_range'], 'base_model' => $f['base_model'],
                'quantization' => $f['quantization'], 'artifacts' => json_encode($artifacts), 'license_id' => $f['license_id'],
                'rights_approval_id' => "Upstream {$f['license_id']} licence; registered from the Local models set " . date('Y-m-d'),
                'min_ram_gib' => $f['min_ram_gib'], 'min_disk_gib' => round(max(0.1, $size / 1073741824 * 1.2), 2), 'state' => 'candidate',
                'state_history' => json_encode([['from' => null, 'to' => 'candidate', 'at' => $now, 'by' => null, 'actor' => null, 'reason' => "Registered from the Local models set: {$f['note']}"]]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->link($svc, $db->table('engine_packages')->where('id', $db->insertID())->get()->getRowArray(), $base);
        }
        return EXIT_SUCCESS;
    }

    /**
     * Hard-link each artifact into the package's download store (writable/engine-artifacts/<package_id>/): same
     * filesystem, so no copy and no extra disk. Run as root (sudo) — the source files belong to another user.
     */
    private function link(EnginePackageService $svc, array $pkg, string $base): void
    {
        $to = $svc->artifactDir($pkg);
        foreach (json_decode((string) $pkg['artifacts'], true) ?: [] as $a) {
            $dst = $to . $a['path'];
            if (is_file($dst)) continue;
            @mkdir(dirname($dst), 0755, true);
            if (! @link($base . '/' . $a['path'], $dst) && ! @copy($base . '/' . $a['path'], $dst)) {
                CLI::error("  could not store {$a['path']} for download");
                continue;
            }
            @chmod($dst, 0644);
        }
        @shell_exec('chown www-data:www-data ' . escapeshellarg(rtrim($to, '/')) . ' 2>/dev/null; find ' . escapeshellarg(rtrim($to, '/')) . ' -type d -exec chown www-data:www-data {} + 2>/dev/null');
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
