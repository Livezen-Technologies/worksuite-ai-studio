<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\SchedulerService;

/**
 * `spark schedule:run` — run all due cron jobs. Call from system crontab every
 * minute:  * * * * * cd /var/www/ollamahub && php spark schedule:run
 */
class ScheduleRun extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'schedule:run';
    protected $description  = 'Run due OllamaHub scheduled jobs.';

    public function run(array $params)
    {
        $svc = new SchedulerService();
        $svc->seedDefaults();
        $out = $svc->runDue();
        if (! $out) {
            CLI::write('No jobs due.', 'yellow');
            return;
        }
        foreach ($out as $key => $res) {
            $ok = $res['ok'] ?? false;
            CLI::write(" - {$key}: " . ($ok ? 'ok' : 'error'), $ok ? 'green' : 'red');
        }
    }
}
