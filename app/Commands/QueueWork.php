<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\QueueService;

/**
 * `spark queue:work` — process pending background jobs.
 * Run via a system service/cron, e.g. every minute: php spark queue:work
 */
class QueueWork extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'queue:work';
    protected $description  = 'Process pending OllamaHub background jobs.';
    protected $usage       = 'queue:work [--limit N]';

    public function run(array $params)
    {
        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 25);
        $res = (new QueueService())->work($limit > 0 ? $limit : 25);
        CLI::write("Processed {$res['processed']} job(s), {$res['failed']} failed.", 'green');
    }
}
