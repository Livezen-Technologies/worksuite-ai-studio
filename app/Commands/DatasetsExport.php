<?php

namespace App\Commands;

use App\Models\DatasetModel;
use App\Services\DatasetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark datasets:export <id> <dir>` — write a frozen dataset's train and dev splits to <dir>/train.jsonl and
 * <dir>/dev.jsonl for a training runner, with a manifest.json naming the dataset version and hashes.
 * The test split is never exported (TRNPIPE 003): it stays on the server for evaluation.
 */
class DatasetsExport extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'datasets:export';
    protected $description = 'Export a frozen dataset\'s train and dev splits (never test) as JSONL for training.';
    protected $usage       = 'datasets:export <id> <dir>';

    public function run(array $params)
    {
        $id  = (int) ($params[0] ?? 0);
        $dir = rtrim((string) ($params[1] ?? ''), '/');
        $ds  = (new DatasetModel())->find($id);
        if (! $ds || $dir === '') {
            CLI::error('Usage: datasets:export <id> <dir>');
            return EXIT_ERROR;
        }
        if (($ds['rights_status'] ?? '') !== 'approved' || empty($ds['manifest_hash'])) {
            CLI::error('Only a rights-approved, frozen dataset can be exported for training.');
            return EXIT_ERROR;
        }
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            CLI::error("Cannot create {$dir}.");
            return EXIT_ERROR;
        }
        $svc = new DatasetService();
        $counts = [];
        foreach (['train', 'dev'] as $split) {
            $n = 0;
            $fh = fopen("{$dir}/{$split}.jsonl", 'wb');
            foreach ($svc->exportJsonl($id, [$split]) as $line) { fwrite($fh, $line); $n++; }
            fclose($fh);
            $counts[$split] = $n;
        }
        file_put_contents("{$dir}/manifest.json", json_encode([
            'dataset_id' => $id, 'name' => $ds['name'], 'version' => (int) $ds['version'],
            'manifest_hash' => $ds['manifest_hash'], 'split_hash' => $ds['split_hash'], 'frozen_at' => $ds['frozen_at'],
            'rows' => $counts, 'exported_at' => gmdate('c'), 'note' => 'test split withheld for evaluation',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        CLI::write("Exported train {$counts['train']} / dev {$counts['dev']} rows to {$dir} (test withheld).", 'green');
        return EXIT_SUCCESS;
    }
}
