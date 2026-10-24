<?php

namespace App\Services;

/**
 * SchedulerService — runs the cron_jobs table. A system cron calls
 * `spark schedule:run` every minute; this picks the jobs that are due (by their
 * preset schedule) and dispatches each to a handler (mostly via QueueService).
 */
class SchedulerService
{
    protected $db;

    /** Seeded default jobs: key => [name, description, handler, schedule]. */
    public const DEFAULTS = [
        'health_check'   => ['Health check', 'Probe every Ollama node', 'health_check', 'every_5m'],
        'sync_models'    => ['Sync models', 'Reconcile installed models per node', 'sync_models', 'hourly'],
        'cleanup_logs'   => ['Cleanup logs', 'Prune API + audit logs older than 30 days', 'cleanup_logs', 'daily'],
        'cleanup_cache'  => ['Clear cache', 'Flush the framework cache', 'cleanup_cache', 'daily'],
        'generate_report'=> ['Daily report', 'Roll up usage statistics', 'generate_report', 'daily'],
        'backup'         => ['Backup', 'Snapshot the database', 'backup', 'daily'],
    ];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** Insert any missing default jobs. */
    public function seedDefaults(): int
    {
        $added = 0;
        foreach (self::DEFAULTS as $key => [$name, $desc, $handler, $schedule]) {
            if (! $this->db->table('cron_jobs')->where('key', $key)->get()->getRowArray()) {
                $this->db->table('cron_jobs')->insert([
                    'key' => $key, 'name' => $name, 'description' => $desc, 'handler' => $handler,
                    'schedule' => $schedule, 'enabled' => 1,
                    'next_run_at' => $this->nextRun($schedule), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $added++;
            }
        }
        return $added;
    }

    /** Run all due, enabled jobs. Returns per-job outcomes. */
    public function runDue(): array
    {
        $now  = date('Y-m-d H:i:s');
        $jobs = $this->db->table('cron_jobs')->where('enabled', 1)
            ->groupStart()->where('next_run_at <=', $now)->orWhere('next_run_at', null)->groupEnd()
            ->get()->getResultArray();
        $out = [];
        foreach ($jobs as $job) {
            $out[$job['key']] = $this->run($job);
        }
        return $out;
    }

    /** Run a single job now (also used by the "run now" button). */
    public function run(array $job): array
    {
        $now = date('Y-m-d H:i:s');
        try {
            $result = $this->dispatch($job['handler']);
            $this->db->table('cron_jobs')->where('id', $job['id'])->update([
                'last_run_at' => $now, 'last_status' => 'ok',
                'last_output' => substr(json_encode($result), 0, 255),
                'next_run_at' => $this->nextRun($job['schedule']),
            ]);
            return ['ok' => true, 'result' => $result];
        } catch (\Throwable $e) {
            $this->db->table('cron_jobs')->where('id', $job['id'])->update([
                'last_run_at' => $now, 'last_status' => 'error',
                'last_output' => substr($e->getMessage(), 0, 255),
                'next_run_at' => $this->nextRun($job['schedule']),
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Most handlers enqueue work; a couple run inline. */
    private function dispatch(string $handler)
    {
        $queue = new QueueService();
        return match ($handler) {
            'health_check'    => ['queued' => $queue->enqueue('health_check')],
            'sync_models'     => ['queued' => $queue->enqueue('sync_models')],
            'cleanup_logs'    => ['queued' => $queue->enqueue('cleanup_logs', ['days' => 30])],
            'generate_report' => (new AnalyticsService())->report(),
            'cleanup_cache'   => ['flushed' => \Config\Services::cache()->clean()],
            'backup'          => $this->backup(),
            default           => throw new \RuntimeException("Unknown handler: {$handler}"),
        };
    }

    private function backup(): array
    {
        // Records a backup intent; an actual mysqldump runs server-side in deployment.
        return ['message' => 'Backup scheduled (runs via server mysqldump in production).', 'at' => date('c')];
    }

    /** Compute the next run time from a preset schedule. */
    public function nextRun(string $schedule, ?int $from = null): string
    {
        $base = $from ?? time();
        $delta = match ($schedule) {
            'every_minute' => 60,
            'every_5m'     => 300,
            'every_15m'    => 900,
            'hourly'       => 3600,
            'daily'        => 86400,
            'weekly'       => 604800,
            default        => 3600,
        };
        return date('Y-m-d H:i:s', $base + $delta);
    }
}
