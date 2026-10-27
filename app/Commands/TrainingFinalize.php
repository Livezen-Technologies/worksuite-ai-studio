<?php

namespace App\Commands;

use App\Services\LlmFinalizeService;
use App\Services\MlModelService;
use App\Services\SpeechModelService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** `spark training:finalize <job>` — build the job's custom LLM (merge → GGUF → quantize → Ollama). Started in the background. */
class TrainingFinalize extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'training:finalize';
    protected $description = 'Finish a trained job: build its custom LLM (merge, GGUF, Ollama) or install its classifier.';
    protected $usage       = 'training:finalize <job id>';

    public function run(array $params)
    {
        $id = (int) ($params[0] ?? 0);
        if ($id <= 0) { CLI::error('Give the job id.'); return EXIT_ERROR; }
        @set_time_limit(0);
        $svc = new LlmFinalizeService();
        $recipe = \Config\Database::connect()->table('training_jobs')->select('recipe')->where('id', $id)->get()->getRow()?->recipe;
        try {
            if ($recipe === 'whisper_custom') {                           // convert + add to the speech catalog
                $r = (new SpeechModelService())->install($id);
                CLI::write("Ready: {$r['key']} ({$r['dir']})", 'green');
                return EXIT_SUCCESS;
            }
            if ($recipe === 'text_classifier') {                         // unpack + register; the ML service loads it on first use
                $r = (new MlModelService())->installClassifier($id);
                CLI::write("Ready: {$r['name']} ({$r['path']})", 'green');
                return EXIT_SUCCESS;
            }
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
