<?php

namespace App\Services;

use App\Models\ServerModel;
use App\Models\ModelModel;
use App\Models\ApiLogModel;

/**
 * ApiGatewayService — node selection (round-robin + failover) for the external
 * `/api/v1` gateway, plus request logging and token-quota checks.
 */
class ApiGatewayService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Healthy nodes that host $model, ordered per the configured load-balancing
     * strategy (round_robin | random | least_busy). The caller walks the list,
     * failing over to the next node on error (when failover is enabled).
     *
     * @return array<array> server rows
     */
    public function candidates(string $model): array
    {
        $cfg = (new SettingsService())->group('gateway', false);
        $statuses = ($cfg['include_degraded'] ?? '1') === '1' ? ['online', 'degraded'] : ['online'];

        $rows = $this->db->table('models m')
            ->select('s.*')
            ->join('servers s', 's.id = m.server_id')
            ->where('m.name', $model)
            ->where('s.deleted_at', null)
            ->whereIn('s.status', $statuses)
            ->orderBy('s.health_score', 'DESC')->orderBy('s.id', 'ASC')
            ->get()->getResultArray();

        $n = count($rows);
        if ($n <= 1) {
            return $rows;
        }

        $candidates = match ($cfg['strategy'] ?? 'round_robin') {
            'random'     => $this->shuffleByModel($rows, $model),
            'least_busy' => $this->orderByLeastBusy($rows),
            default      => $this->rotate($rows, $model),
        };

        // When failover is off, the caller still receives the full list but the
        // gateway controller honours max_retries=1 effectively via the setting.
        return ($cfg['failover'] ?? '1') === '1' ? $candidates : [$candidates[0]];
    }

    /** Round-robin: rotate by a per-model counter in cache. */
    private function rotate(array $rows, string $model): array
    {
        $cache = \Config\Services::cache();
        $ckey  = 'gw_rr_' . md5($model);
        $i     = (int) ($cache->get($ckey) ?? 0);
        $cache->save($ckey, $i + 1, 3600);
        $offset = $i % count($rows);
        return array_merge(array_slice($rows, $offset), array_slice($rows, 0, $offset));
    }

    /** Deterministic-ish shuffle seeded by a per-model counter (no Math.random reliance). */
    private function shuffleByModel(array $rows, string $model): array
    {
        $cache = \Config\Services::cache();
        $ckey  = 'gw_rnd_' . md5($model);
        $i     = (int) ($cache->get($ckey) ?? 0);
        $cache->save($ckey, $i + 1, 3600);
        usort($rows, static fn ($a, $b) => (($a['id'] * 1103515245 + $i) % 97) <=> (($b['id'] * 1103515245 + $i) % 97));
        return $rows;
    }

    /** Least-busy: fewest gateway requests in the last 5 minutes first. */
    private function orderByLeastBusy(array $rows): array
    {
        $since = date('Y-m-d H:i:s', time() - 300);
        foreach ($rows as &$r) {
            $r['_load'] = $this->db->table('api_logs')->where('server_id', $r['id'])->where('created_at >=', $since)->countAllResults();
        }
        unset($r);
        usort($rows, static fn ($a, $b) => $a['_load'] <=> $b['_load']);
        return $rows;
    }

    /** Record one gateway request. Returns the inserted id. */
    public function log(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        return (int) (new ApiLogModel())->insert($data, true);
    }

    /**
     * Check daily/monthly token quotas for a key. Returns an error string when a
     * limit is already exceeded, or null when within quota.
     */
    public function quotaError(array $key): ?string
    {
        $logs = new ApiLogModel();
        if (! empty($key['daily_token_limit'])) {
            $used = $logs->tokensSince((int) $key['id'], date('Y-m-d 00:00:00'));
            if ($used >= (int) $key['daily_token_limit']) {
                return 'Daily token limit reached.';
            }
        }
        if (! empty($key['monthly_token_limit'])) {
            $used = $logs->tokensSince((int) $key['id'], date('Y-m-01 00:00:00'));
            if ($used >= (int) $key['monthly_token_limit']) {
                return 'Monthly token limit reached.';
            }
        }
        return null;
    }

    /** Models available through the gateway (installed on reachable nodes). */
    public function availableModels(): array
    {
        return $this->db->table('models m')
            ->select('m.name, m.parameter_size, m.quantization, s.name AS server, s.status')
            ->join('servers s', 's.id = m.server_id')
            ->where('s.deleted_at', null)
            ->groupBy('m.name')
            ->orderBy('m.name', 'ASC')
            ->get()->getResultArray();
    }
}
