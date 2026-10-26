<?php

namespace App\Services;

use App\Models\ServerModel;
use App\Models\ServerHealthModel;

/**
 * ServerService — node registry + health/heartbeat orchestration.
 *
 * Wraps OllamaService probes, derives a 0–100 health score, persists the
 * current status onto the server row, and appends a server_health time-series
 * record on every check.
 */
class ServerService
{
    protected ServerModel $servers;
    protected ServerHealthModel $health;

    public function __construct()
    {
        $this->servers = new ServerModel();
        $this->health  = new ServerHealthModel();
    }

    /** Build an OllamaService bound to a server row. */
    public function clientFor(array $server): OllamaService
    {
        return new OllamaService($server['api_url'], $server['auth_token'] ?? null);
    }

    /**
     * Probe a node, persist status + score, append a health record.
     * Returns the probe result merged with the derived status/score.
     */
    public function check(array $server): array
    {
        $provider = new ProviderService();
        $probe    = $provider->isExternal($server['kind'] ?? 'ollama')
            ? $provider->probe($server)
            : $this->clientFor($server)->probe();
        $score = $this->score($probe, ! empty($server['tunnel_port']));
        $status = $this->statusFromScore($probe['ok'], $score);
        $now   = date('Y-m-d H:i:s');

        $update = [
            'status'            => $status,
            'health_score'      => $score,
            'last_heartbeat_at' => $now,
        ];
        // Opportunistically capture the version when we learn it.
        if (! empty($probe['version'])) {
            $update['ollama_version'] = $probe['version'];
        }
        $this->servers->update($server['id'], $update);

        $this->health->insert([
            'server_id'      => $server['id'],
            'status'         => $status,
            'health_score'   => $score,
            'latency_ms'     => $probe['latency_ms'],
            'models_total'   => $probe['models_total'],
            'models_running' => $probe['models_running'],
            'error'          => $probe['error'] ? substr($probe['error'], 0, 255) : null,
            'checked_at'     => $now,
        ]);

        return array_merge($probe, ['status' => $status, 'health_score' => $score]);
    }

    /** Check a server by id. Returns null if the server is gone. */
    public function checkById(int $id): ?array
    {
        $server = $this->servers->find($id);
        if (! $server) {
            return null;
        }
        return $this->check($server);
    }

    /**
     * Derive a 0–100 health score from a probe:
     *  - unreachable => 0
     *  - reachable   => base 100, penalised for latency.
     * A node behind the reverse SSH tunnel answers across the internet (~450 ms is normal), so its thresholds are
     * higher: −10 over 1 s, −15 more over 2 s, −25 more over 4 s. Local / LAN nodes keep 250 ms / 750 ms / 1.5 s.
     */
    protected function score(array $probe, bool $tunnel = false): int
    {
        if (! $probe['ok']) {
            return 0;
        }
        $latency = (int) $probe['latency_ms'];
        [$a, $b, $c] = $tunnel ? [1000, 2000, 4000] : [250, 750, 1500];
        $penalty = 0;
        if ($latency > $a) $penalty += 10;
        if ($latency > $b) $penalty += 15;
        if ($latency > $c) $penalty += 25;
        return max(40, 100 - $penalty);
    }

    protected function statusFromScore(bool $ok, int $score): string
    {
        if (! $ok) {
            return 'offline';
        }
        return $score >= 70 ? 'online' : 'degraded';
    }
}
