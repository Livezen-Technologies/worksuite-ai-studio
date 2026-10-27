<?php

namespace App\Commands;

use App\Services\SpeechDatasetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** `spark datasets:import-speech <dataset id> <zip>` — import a zip of recordings + transcripts on the server (large sets). */
class DatasetsImportSpeech extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'datasets:import-speech';
    protected $description = 'Import a zip of recordings and transcripts into a speech dataset (as the Datasets page does).';
    protected $usage       = 'datasets:import-speech <dataset id> <zip path>';

    public function run(array $params)
    {
        [$id, $zip] = [(int) ($params[0] ?? 0), (string) ($params[1] ?? '')];
        if ($id <= 0 || ! is_file($zip)) { CLI::error('Usage: datasets:import-speech <dataset id> <zip path>'); return EXIT_ERROR; }
        $r = (new SpeechDatasetService())->importZip($id, $zip);
        CLI::write("{$r['imported']} recordings imported, {$r['skipped']} skipped.", 'green');
        foreach ($r['issues'] as $i) CLI::write("  - {$i}", 'yellow');
        return EXIT_SUCCESS;
    }
}
