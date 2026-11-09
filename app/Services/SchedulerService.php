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
        'cleanup_cache'  => ['Prune cache', 'Remove expired cache entries (rate-limit counters and routing state are kept)', 'cleanup_cache', 'daily'],
        'generate_report'=> ['Daily report', 'Roll up usage statistics', 'generate_report', 'daily'],
        'backup'         => ['Backup', 'Snapshot the database', 'backup', 'daily'],
        'training_pipelines' => ['Training pipelines', 'Start runs when data changes; retry or fail stalled jobs', 'training_pipelines', 'every_minute'],
        'storage_health' => ['Storage health', 'Check every storage node and evaluate alert rules', 'storage_health', 'every_5m'],
        'storage_backups' => ['Storage backups', 'Start due database backups and mark dead runs as failed', 'storage_backups', 'every_5m'],
        'model_autoload' => ['Model load policy', 'Keep pinned models loaded and unload idle ones', 'model_autoload', 'every_5m'],
        'storage_sync'   => ['Storage sync', 'Replicate, move and delete file copies across storage nodes', 'storage_sync', 'every_minute'],
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
            // Claim the run first: move next_run_at on only if nobody else has. A job still running from
            // the previous minute's cron (a long sync) is then not started a second time alongside it.
            // Still running from an earlier tick (started under an hour ago)? Leave it alone.
            if (($job['last_status'] ?? '') === 'running' && ! empty($job['updated_at']) && strtotime($job['updated_at']) > time() - 3600) continue;
            $b = $this->db->table('cron_jobs')->where('id', $job['id']);
            $job['next_run_at'] === null ? $b->where('next_run_at', null) : $b->where('next_run_at', $job['next_run_at']);
            $b->update(['next_run_at' => $this->nextRun($job['schedule']), 'last_status' => 'running', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->affectedRows() === 0) continue;
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
            'cleanup_cache'   => $this->pruneCache(),
            'backup'          => $this->backup(),
            'training_pipelines' => (new TrainingPipelineService())->tick(),
            'storage_health' => (new StorageService())->checkAll(),
            'storage_sync'   => (new ObjectStoreService())->sync(),
            'storage_backups' => (new StorageBackupService())->tick(),
            'model_autoload' => (new ModelRegistryService())->autoload(),
            default           => throw new \RuntimeException("Unknown handler: {$handler}"),
        };
    }

    /**
     * Remove only expired cache entries. A full flush would also reset the live rate-limit counters,
     * failed-login throttles and gateway round-robin state, which are short-lived by design.
     */
    public function pruneCache(?\CodeIgniter\Cache\CacheInterface $cache = null): array
    {
        $cache ??= \Config\Services::cache();
        $info  = $cache->getCacheInfo();
        if (! is_array($info)) return ['pruned' => 0];
        $before = count($info);
        foreach (array_keys($info) as $key) $cache->get((string) $key); // reading an expired entry deletes it
        $after = $cache->getCacheInfo();
        return ['pruned' => $before - (is_array($after) ? count($after) : 0), 'kept' => is_array($after) ? count($after) : 0];
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
