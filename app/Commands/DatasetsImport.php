<?php

namespace App\Commands;

use App\Models\DatasetModel;
use App\Services\DatasetService;
use App\Services\EngineDatasetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark datasets:import` — import a JSONL / JSON / CSV file that is already on the server into a dataset, with its
 * provenance and rights recorded in the same step (SRS FR TRNPIPE 001).
 *
 * For sources too large to upload through the browser (open corpora downloaded straight to the server). Rows go
 * through DatasetService::import, so field aliases, duplicate skipping and token estimates are the same as an upload.
 *
 *   php spark datasets:import /srv/sinhala-data/prepared/aya_dataset_si.jsonl --name "Aya Dataset — Sinhala" \
 *       --language si --source "https://huggingface.co/datasets/CohereLabs/aya_dataset" \
 *       --use "Commercial model training" --rights "Apache-2.0" --approve
 *
 * --dataset ID appends to an existing dataset instead of creating one. --approve marks the rights approved; it is
 * refused unless source, permitted use and rights are all given (the same rule as the Governance tab).
 */
class DatasetsImport extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'datasets:import';
    protected $description = 'Import a server-side JSONL/JSON/CSV file into a dataset, recording source and rights.';
    protected $usage       = 'datasets:import <file> [--name N] [--dataset ID] [--language si] [--task text] [--description D] [--source S] [--use U] [--rights R] [--approve]';

    public function run(array $params)
    {
        $file = $params[0] ?? null;
        if (! $file || ! is_file($file) || ! is_readable($file)) {
            CLI::error('Give the path of a readable JSONL, JSON or CSV file.');
            return EXIT_ERROR;
        }

        $opt = static fn (string $k) => CLI::getOption($k) ?? $params[$k] ?? null;
        $datasets = new DatasetModel();
        $id = (int) ($opt('dataset') ?? 0);
        if ($id > 0) {
            if (! $datasets->find($id)) {
                CLI::error("Dataset {$id} not found.");
                return EXIT_ERROR;
            }
        } else {
            $name = trim((string) ($opt('name') ?? ''));
            if ($name === '') {
                CLI::error('--name is required when creating a dataset.');
                return EXIT_ERROR;
            }
            $id = (int) $datasets->insert([
                'name'        => $name,
                'description' => (string) ($opt('description') ?? ''),
                'language'    => (string) ($opt('language') ?? 'si'),
                'item_count'  => 0,
                'token_estimate' => 0,
            ]);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $res = (new DatasetService())->import($id, (string) file_get_contents($file), $ext);
        CLI::write("Dataset {$id}: imported {$res['imported']} row(s), skipped {$res['skipped']}.", 'green');

        $rights = array_filter([
            'task'          => $opt('task') ?? 'text',
            'source_ref'    => $opt('source'),
            'permitted_use' => $opt('use'),
            'rights_ref'    => $opt('rights'),
        ], static fn ($v) => $v !== null && $v !== '');
        $approve = CLI::getOption('approve') !== null || array_key_exists('approve', $params);
        try {
            $ds = (new EngineDatasetService())->setRights($id, $rights + ['approve' => $approve]);
            CLI::write("Rights: {$ds['rights_status']}.", $ds['rights_status'] === 'approved' ? 'green' : 'yellow');
        } catch (\RuntimeException $e) {
            CLI::error('Rows imported, but rights were not approved: ' . $e->getMessage());
            return EXIT_ERROR;
        }
        return EXIT_SUCCESS;
    }
}
