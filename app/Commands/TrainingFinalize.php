<?php

namespace App\Commands;

use App\Services\LlmFinalizeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** `spark training:finalize <job>` — build the job's custom LLM (merge → GGUF → quantize → Ollama). Started in the background. */
class TrainingFinalize extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'training:finalize';
    protected $description = 'Build a custom LLM from a finished text_sft job: merge, GGUF, quantize, add to Ollama.';
    protected $usage       = 'training:finalize <job id>';

    public function run(array $params)
    {
        $id = (int) ($params[0] ?? 0);
        if ($id <= 0) { CLI::error('Give the job id.'); return EXIT_ERROR; }
        @set_time_limit(0);
        $svc = new LlmFinalizeService();
        try {
            $r = $svc->run($id);
            CLI::write("Ready: {$r['name']} ({$r['gguf']})", 'green');
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            $svc->fail($id, $e->getMessage());
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
    }
}
