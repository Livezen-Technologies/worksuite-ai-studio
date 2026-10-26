<?php

namespace App\Services;

/**
 * MonitoringService — dashboard statistics + time-series charts (from the DB),
 * and live system/GPU metrics per node (from Prometheus when it is configured
 * and reachable, otherwise gracefully reported as unavailable).
 */
class MonitoringService
{
    protected $db;
    protected ?string $promBase;

    public function __construct()
    {
        $this->db       = \Config\Database::connect();
        $base           = (string) env('prometheus.baseURL', '');
        $this->promBase = $base !== '' ? rtrim($base, '/') : null;
    }

    // ---- Dashboard ---------------------------------------------------------

    /** The headline stat-card numbers (all from real DB state). */
    public function stats(): array
    {
        $servers = $this->db->table('servers')->where('deleted_at', null);
        $total   = (clone $servers)->countAllResults();
        $online  = $this->db->table('servers')->where('deleted_at', null)->where('status', 'online')->countAllResults();

        $models      = $this->db->table('models')->countAllResults();
        $running     = $this->db->table('models')->where('status', 'running')->countAllResults();

        // Requests/tokens/latency across BOTH the Playground (chat_messages) and
        // the external API gateway (api_logs) — see events().
        $row = $this->db->query(
            "SELECT COUNT(*) reqs, COALESCE(SUM(pt+ct),0) toks, AVG(ms) avg_ms FROM ({$this->events()}) e"
        )->getRowArray();
        $requests    = (int) ($row['reqs'] ?? 0);
        $totalTokens = (int) ($row['toks'] ?? 0);
        $avgMs       = $row['avg_ms'] !== null ? (int) round((float) $row['avg_ms']) : null;

        $users       = $this->db->table('users')->where('deleted_at', null)->countAllResults();
        $activeUsers = $this->db->table('users')->where('deleted_at', null)
            ->where('last_login_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))->countAllResults();

        // Cluster CPU/GPU averages from Prometheus (null when unavailable).
        $cpu = $this->avgPromMetric('100 - (avg(rate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)');
        $gpu = $this->avgPromMetric('avg(nvidia_smi_utilization_gpu_ratio) * 100');

        return [
            'total_servers'   => $total,
            'online_servers'  => $online,
            'total_models'    => $models,
            'active_models'   => $running,
            'api_requests'    => $requests,
            'total_tokens'    => $totalTokens,
            'avg_response_ms' => $avgMs,
            'error_rate'      => $this->errorRate(),
            'total_users'     => $users,
            'active_users'    => $activeUsers,
            'cpu_usage'       => $cpu,
            'gpu_usage'       => $gpu,
            'prometheus'      => $this->promReachable(),
        ];
    }

    /** Time-series + breakdown data for the dashboard charts. */
    public function charts(): array
    {
        return [
            'requests_per_hour' => $this->requestsPerHour(),
            'tokens_per_day'    => $this->tokensPerDay(),
            'model_usage'       => $this->modelUsage(),
            'server_health'     => $this->serverHealth(),
        ];
    }

    /**
     * Unified activity feed: assistant turns from the Playground plus every
     * gateway request, normalised to (created_at, model, pt, ct, ms). Used by
     * all request/token/model/latency metrics so both sources are counted.
     */
    private function events(): string
    {
        return "SELECT created_at, COALESCE(model,'unknown') AS model,
                       COALESCE(prompt_tokens,0) AS pt, COALESCE(completion_tokens,0) AS ct, total_ms AS ms
                FROM chat_messages WHERE role='assistant'
                UNION ALL
                SELECT created_at, COALESCE(model,'unknown') AS model,
                       COALESCE(prompt_tokens,0) AS pt, COALESCE(completion_tokens,0) AS ct, processing_ms AS ms
                FROM api_logs";
    }

    private function requestsPerHour(): array
    {
        $rows = $this->db->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m-%d %H:00') AS bucket, COUNT(*) AS n
             FROM ({$this->events()}) e WHERE created_at >= NOW() - INTERVAL 24 HOUR
             GROUP BY bucket ORDER BY bucket"
        )->getResultArray();
        return array_map(static fn ($r) => ['t' => $r['bucket'], 'requests' => (int) $r['n']], $rows);
    }

    private function tokensPerDay(): array
    {
        $rows = $this->db->query(
            "SELECT DATE(created_at) AS day, COALESCE(SUM(pt),0) AS p, COALESCE(SUM(ct),0) AS c
             FROM ({$this->events()}) e WHERE created_at >= NOW() - INTERVAL 7 DAY
             GROUP BY day ORDER BY day"
        )->getResultArray();
        return array_map(static fn ($r) => [
            't' => $r['day'], 'prompt' => (int) $r['p'], 'completion' => (int) $r['c'],
        ], $rows);
    }

    private function modelUsage(): array
    {
        $rows = $this->db->query(
            "SELECT model, COUNT(*) AS n FROM ({$this->events()}) e GROUP BY model ORDER BY n DESC LIMIT 8"
        )->getResultArray();
        return array_map(static fn ($r) => ['model' => $r['model'], 'requests' => (int) $r['n']], $rows);
    }

    private function serverHealth(): array
    {
        $rows = $this->db->table('servers')->select('name, status, health_score')
            ->where('deleted_at', null)->orderBy('id', 'ASC')->get()->getResultArray();
        return array_map(static fn ($r) => [
            'name' => $r['name'], 'status' => $r['status'], 'score' => (int) $r['health_score'],
        ], $rows);
    }

    private function errorRate(): float
    {
        // Wired to api_logs in Phase 6; 0 until the gateway records traffic.
        if (! $this->db->tableExists('api_logs')) {
            return 0.0;
        }
        $total = $this->db->table('api_logs')->countAllResults(false);
        if ($total === 0) {
            return 0.0;
        }
        $errors = $this->db->table('api_logs')->where('status >=', 400)->countAllResults();
        return round($errors / $total * 100, 2);
    }

    // ---- Live node metrics (Prometheus) -----------------------------------

    /** Per-node live metrics; each node flagged available=false when unscraped. */
    public function nodeMetrics(): array
    {
        $servers = $this->db->table('servers')->where('deleted_at', null)->get()->getResultArray();
        $out = [];
        foreach ($servers as $s) {
            // Nodes enrolled with the AI Studio Node app report their own metrics through their tunnel.
            if (! empty($s['tunnel_port']) && ($m = $this->nodeAppMetrics($s['api_url'])) !== null) {
                $out[] = [
                    'server_id' => (int) $s['id'],
                    'name'      => $s['name'],
                    'status'    => $s['status'],
                    'cpu_pct'   => $this->round($m['cpu_pct'] ?? null),
                    'mem_pct'   => $this->round($m['mem_pct'] ?? null),
                    'disk_pct'  => $this->round($m['disk_pct'] ?? null),
                    'gpu_pct'   => $this->round($m['gpu_pct'] ?? null),
                    'vram_pct'  => $this->round($m['vram_pct'] ?? null),
                    'gpu_temp_c'=> $this->round($m['gpu_temp_c'] ?? null),
                    'source'    => 'node-app',
                    'available' => true,
                ];
                continue;
            }
            $instance = $s['ip_address'] ?: parse_url($s['api_url'], PHP_URL_HOST);
            $out[] = [
                'server_id' => (int) $s['id'],
                'name'      => $s['name'],
                'status'    => $s['status'],
                'source'    => 'prometheus',
                // 1-minute window so the figure follows the load instead of a 5-minute average.
                'cpu_pct'   => $this->promInstant("100 - (avg by(instance)(rate(node_cpu_seconds_total{mode=\"idle\",instance=~\"{$instance}.*\"}[1m]))*100)"),
                'mem_pct'   => $this->promInstant("(1 - node_memory_MemAvailable_bytes{instance=~\"{$instance}.*\"} / node_memory_MemTotal_bytes{instance=~\"{$instance}.*\"})*100"),
                'disk_pct'  => $this->promInstant("(1 - node_filesystem_avail_bytes{instance=~\"{$instance}.*\",mountpoint=\"/\"} / node_filesystem_size_bytes{instance=~\"{$instance}.*\",mountpoint=\"/\"})*100"),
                'gpu_pct'   => $this->promInstant("nvidia_smi_utilization_gpu_ratio{instance=~\"{$instance}.*\"}*100"),
                'available' => $this->promReachable(),
            ];
        }
        return $out;
    }

    /** GET {api_url}/aistudio-node/metrics from a node app (null when it doesn't answer, e.g. a CLI-script node). */
    private function nodeAppMetrics(string $apiUrl): ?array
    {
        try {
            $res = \Config\Services::curlrequest([], null, null, false)->get(rtrim($apiUrl, '/') . '/aistudio-node/metrics', [
                'http_errors' => false, 'connect_timeout' => 2, 'timeout' => 3,
            ]);
            $json = $res->getStatusCode() === 200 ? json_decode((string) $res->getBody(), true) : null;
            return is_array($json) && isset($json['cpu_pct']) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function round($v): ?float
    {
        return is_numeric($v) ? round((float) $v, 1) : null;
    }

    // ---- Prometheus helpers (all null-safe) -------------------------------

    public function promReachable(): bool
    {
        return $this->promBase !== null && $this->promInstant('vector(1)') !== null;
    }

    /** Instant query returning a single scalar, or null on any failure. */
    private function promInstant(string $query): ?float
    {
        if ($this->promBase === null) {
            return null;
        }
        try {
            $client = \Config\Services::curlrequest([], null, null, false);
            $res = $client->get($this->promBase . '/api/v1/query', [
                'query' => ['query' => $query], 'http_errors' => false,
                'connect_timeout' => 2, 'timeout' => 4,
            ]);
            if ($res->getStatusCode() !== 200) {
                return null;
            }
            $json = json_decode((string) $res->getBody(), true);
            $val  = $json['data']['result'][0]['value'][1] ?? null;
            return $val !== null ? round((float) $val, 2) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function avgPromMetric(string $query): ?float
    {
        return $this->promInstant($query);
    }
}
