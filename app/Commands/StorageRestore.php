<?php

namespace App\Commands;

use App\Services\StorageRestoreService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** `spark storage:restore` — the detached process behind a database restore started on the Backups tab. */
class StorageRestore extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'storage:restore';
    protected $description = 'Run the pending database restore (started from Storage → Backups).';

    public function run(array $params)
    {
        (new StorageRestoreService())->run();
        $s = StorageRestoreService::status();
        CLI::write('restore: ' . ($s['state'] ?? 'none') . (isset($s['error']) ? ' — ' . $s['error'] : ''));
        return ($s['state'] ?? '') === 'done' ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
