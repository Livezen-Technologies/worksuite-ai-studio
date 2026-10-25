<?php

namespace App\Commands;

use App\Services\EngineGateService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark engine:record-bench <results.json> [--package id]` — record a server benchmark run (engine_bench.py) as Engine
 * evaluations: one for ASR, one per text model. Each is gated by EngineGateService exactly as a GPU worker's would be;
 * the decision is the gate's, never this command's. With --package the ASR evaluation is linked to that package.
 * Approvals (release + language) stay with people on the Engine page.
 */
class EngineRecordBench extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'engine:record-bench';
    protected $description = 'Record a server benchmark results.json as gated Engine evaluations.';
    protected $usage       = 'engine:record-bench <results.json> [--package id]';

    public function run(array $params)
    {
        $file = $params[0] ?? '';
        if (! is_file($file)) {
            CLI::error('Give the path to results.json.');
            return EXIT_ERROR;
        }
        $raw   = file_get_contents($file);
        $res   = json_decode($raw, true) ?: [];
        $db    = \Config\Database::connect();
        $gate  = new EngineGateService();
        $suite = hash('sha256', (string) @file_get_contents('/home/ubuntu/sinhala-build/engine_bench.py') ?: $raw);
        $hw    = trim((string) @shell_exec('nproc')) . ' CPU cores, ' . round(((int) @shell_exec("awk '/MemTotal/{print $2}' /proc/meminfo")) / 1048576) . ' GiB RAM, no GPU';
        $now   = date('Y-m-d H:i:s');

        $rows = [];
        if (! empty($res['asr'])) {
            $rows[] = ['asr', $res['asr'], 'Whisper Sinhala (SinhaSpeech run11, int8)', 'SinhaSpeech stratified_v6 test split, ' . ($res['asr']['clips'] ?? '?') . ' clips + ' . ($res['asr']['silence_clips'] ?? '?') . ' silence clips'];
        }
        foreach ($res['text'] ?? [] as $t) {
            if (! empty($t['error'])) continue;
            $m = array_diff_key($t, ['model' => 1, 'probes' => 1]);
            $rows[] = ['text', $m, (string) $t['model'], 'WorkSuite Sinhala v1 test split (dataset 10), placeholders, JSON, probes; ' . ($t['tokens_per_second'] ?? '?') . ' tok/s'];
        }
        $asrEval = null;
        foreach ($rows as [$task, $metrics, $candidate, $notes]) {
            $g    = $gate->evaluate($task, $metrics);
            $uuid = $this->uuid();
            $db->table('engine_evaluations')->insert([
                'uuid' => $uuid, 'task' => $task, 'candidate_digest' => hash('sha256', $candidate), 'suite_digest' => $suite,
                'hardware' => $hw, 'runtime_lock' => $task === 'asr' ? 'faster-whisper 1.x, CTranslate2 int8' : 'ollama ' . trim((string) @shell_exec('ollama --version 2>/dev/null | grep -o "[0-9.]*$"')),
                'metrics' => json_encode($metrics), 'gate_result' => json_encode($g), 'decision' => $g['pass'] ? 'pass' : 'fail',
                'notes' => "{$candidate} — {$notes}. Server benchmark started {$res['started']}.", 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($task === 'asr') $asrEval = (int) $db->insertID();
            CLI::write(sprintf('%-5s %-40s %s', $task, $candidate, $g['pass'] ? 'PASS' : 'FAIL'), $g['pass'] ? 'green' : 'yellow');
            foreach ($g['checks'] as $c) {
                CLI::write('      ' . $c['status'] . '  ' . $c['label'] . (isset($c['value']) ? ' = ' . $c['value'] : ''));
            }
        }
        $pkg = CLI::getOption('package');
        if ($pkg && $asrEval) {
            $db->table('engine_packages')->where('id', (int) $pkg)->where('task', 'asr')->update(['evaluation_id' => $asrEval, 'updated_at' => $now]);
            CLI::write("Linked the ASR evaluation to package #{$pkg}.");
        }
        return EXIT_SUCCESS;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
