<?php

namespace App\Commands;

use App\Services\EngineDatasetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark datasets:freeze <id> [--train 0.8] [--dev 0.1]` — the Splits tab's "Freeze vN+1" from the command line:
 * grouped deterministic train / dev / test split and a new immutable manifest version (TRNPIPE 003).
 */
class DatasetsFreeze extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'datasets:freeze';
    protected $description = 'Freeze a rights-approved dataset into grouped train/dev/test splits (new manifest version).';
    protected $usage       = 'datasets:freeze <id> [--train 0.8] [--dev 0.1]';

    public function run(array $params)
    {
        $id = (int) ($params[0] ?? 0);
        $train = (float) (CLI::getOption('train') ?? $params['train'] ?? 0.8);
        $dev   = (float) (CLI::getOption('dev') ?? $params['dev'] ?? 0.1);
        try {
            $r = (new EngineDatasetService())->freeze($id, ['train' => $train, 'dev' => $dev]);
        } catch (\RuntimeException $e) {
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
        $ds = $r['dataset'] ?? $r;
        CLI::write(sprintf('Dataset %d frozen as v%s · manifest %s', $id, $ds['version'] ?? '?', $ds['manifest_hash'] ?? '?'), 'green');
        if (isset($r['split_counts'])) CLI::write('Splits: ' . json_encode($r['split_counts']));
        return EXIT_SUCCESS;
    }
}
