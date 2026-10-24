<?php

namespace App\Services;

/**
 * QueueService — DB-backed background job queue (Redis-ready: swap the storage
 * methods for a Redis list in production without touching the handlers).
 *
 * Job types map to handlers that call the underlying services. A worker
 * (`spark queue:work`) claims pending jobs and processes them.
 */
class QueueService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** Enqueue a job. Returns its id. */
    public function enqueue(string $type, array $payload = [], int $priority = 5, int $maxAttempts = 3): int
    {
        return (int) ($this->db->table('queues')->insert([
            'type' => $type, 'payload' => json_encode($payload), 'status' => 'pending',
            'priority' => $priority, 'max_attempts' => $maxAttempts,
            'available_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $this->db->insertID() : 0);
    }

    /** Atomically claim the next runnable job (or null). */
    public function claim(): ?array
    {
        $now = date('Y-m-d H:i:s');
        $job = $this->db->table('queues')
            ->where('status', 'pending')->where('available_at <=', $now)
            ->orderBy('priority', 'ASC')->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();
        if (! $job) {
            return null;
        }
        // Claim it: only succeeds if still pending (guards concurrent workers).
        $this->db->table('queues')->where('id', $job['id'])->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => $now, 'attempts' => $job['attempts'] + 1]);
        if ($this->db->affectedRows() === 0) {
            return null; // taken by another worker
        }
        return $this->db->table('queues')->where('id', $job['id'])->get()->getRowArray();
    }

    /** Run one claimed job through its handler; records the outcome. */
    public function process(array $job): array
    {
        $payload = $job['payload'] ? json_decode($job['payload'], true) : [];
        try {
            $result = $this->handle($job['type'], $payload);
            $this->db->table('queues')->where('id', $job['id'])->update([
                'status' => 'completed', 'finished_at' => date('Y-m-d H:i:s'),
                'result' => substr(json_encode($result), 0, 60000), 'error' => null,
            ]);
            return ['ok' => true, 'result' => $result];
        } catch (\Throwable $e) {
            $failed = ((int) $job['attempts']) >= ((int) $job['max_attempts']);
            $this->db->table('queues')->where('id', $job['id'])->update([
                'status' => $failed ? 'failed' : 'pending',
                'available_at' => $failed ? null : date('Y-m-d H:i:s', time() + 30),
                'finished_at' => $failed ? date('Y-m-d H:i:s') : null,
                'error' => substr($e->getMessage(), 0, 255),
            ]);
            return ['ok' => false, 'error' => $e->getMessage(), 'retry' => ! $failed];
        }
    }

    /** Process up to $limit jobs. Returns counts. Used by `spark queue:work`. */
    public function work(int $limit = 25): array
    {
        $done = 0; $failed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->claim();
            if (! $job) break;
            $res = $this->process($job);
            $res['ok'] ? $done++ : $failed++;
        }
        return ['processed' => $done, 'failed' => $failed];
    }

    /** Dispatch a job type to its handler. */
    public function handle(string $type, array $payload)
    {
        return match ($type) {
            'sync_models'     => (new ModelService())->syncAll(),
            'health_check'    => $this->healthCheckAll(),
            'pull_model'      => $this->pullModel($payload),
            'generate_report' => (new AnalyticsService())->report(),
            'cleanup_logs'    => $this->cleanupLogs((int) ($payload['days'] ?? 30)),
            'noop'            => ['message' => 'noop ok', 'echo' => $payload],
            default           => throw new \RuntimeException("Unknown job type: {$type}"),
        };
    }

    private function healthCheckAll(): array
    {
        $svc = new ServerService();
        $servers = $this->db->table('servers')->where('deleted_at', null)->get()->getResultArray();
        $out = [];
        foreach ($servers as $s) {
            $r = $svc->check($s);
            $out[$s['name']] = $r['status'];
        }
        return $out;
    }

    private function pullModel(array $payload): array
    {
        $server = $this->db->table('servers')->where('id', (int) ($payload['server_id'] ?? 0))->get()->getRowArray();
        if (! $server) throw new \RuntimeException('Server not found.');
        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null);
        $final = $client->pull((string) ($payload['name'] ?? ''));
        (new ModelService())->syncServer($server);
        return $final;
    }

    private function cleanupLogs(int $days): array
    {
        $cut = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $api = $this->db->table('api_logs')->where('created_at <', $cut)->delete();
        $audit = $this->db->table('audit_logs')->where('created_at <', $cut)->delete();
        return ['api_logs_pruned' => $this->db->affectedRows(), 'cutoff' => $cut];
    }

    public function stats(): array
    {
        $t = $this->db->table('queues');
        return [
            'pending'   => (clone $t)->where('status', 'pending')->countAllResults(),
            'running'   => (clone $t)->where('status', 'running')->countAllResults(),
            'completed' => (clone $t)->where('status', 'completed')->countAllResults(),
            'failed'    => (clone $t)->where('status', 'failed')->countAllResults(),
            'cancelled' => (clone $t)->where('status', 'cancelled')->countAllResults(),
        ];
    }
}
