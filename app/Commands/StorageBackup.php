<?php

namespace App\Commands;

use App\Services\StorageBackupService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** `spark storage:backup <run id>` — the detached process behind one backup run (started by the scheduler or Run now). */
class StorageBackup extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'storage:backup';
    protected $description = 'Dump the database for one backup run and store it in the object store.';
    protected $usage       = 'storage:backup <run id>';

    public function run(array $params)
    {
        if (! isset($params[0])) { CLI::error('Give the run id.'); return EXIT_ERROR; }
        (new StorageBackupService())->run((int) $params[0]);
        $r = \Config\Database::connect()->table('storage_backup_runs')->where('id', (int) $params[0])->get()->getRowArray();
        CLI::write("run #{$params[0]}: " . ($r['status'] ?? 'missing') . ($r['error'] ?? '' ? ' — ' . $r['error'] : ''));
        return ($r['status'] ?? '') === 'ok' ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
