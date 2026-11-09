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
            ->where('s.is_enabled', 1) // a disabled node gets no gateway traffic
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
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        // One grouped query instead of one count per server on every gateway request.
        $load = $ids ? array_column($this->db->table('api_logs')->select('server_id, COUNT(*) AS n')
            ->whereIn('server_id', $ids)->where('created_at >=', $since)->groupBy('server_id')
            ->get()->getResultArray(), 'n', 'server_id') : [];
        foreach ($rows as &$r) {
            $r['_load'] = (int) ($load[$r['id']] ?? 0);
        }
        unset($r);
        usort($rows, static fn ($a, $b) => $a['_load'] <=> $b['_load']);
        return $rows;
    }

    /** Record one gateway request. Returns the inserted id. */
    /**
     * Rough token count (about 4 characters a token) for calls whose provider reports no usage, so
     * they still count toward quotas instead of being free.
     */
    public static function estimateTokens($value): int
    {
        if ($value === null || $value === '' || $value === []) return 0;
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

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
    /** $servable: only models on at least one enabled node that can take traffic (as candidates() would route). */
    public function availableModels(bool $servable = false): array
    {
        // Read the setting before building the query: another query mid-build clobbers the table alias.
        $statuses = null;
        if ($servable) {
            $cfg = (new SettingsService())->group('gateway', false);
            $statuses = ($cfg['include_degraded'] ?? '1') === '1' ? ['online', 'degraded'] : ['online'];
        }
        $b = $this->db->table('models m')
            // Every selected column is aggregated, so this runs under MySQL's ONLY_FULL_GROUP_BY.
            ->select('m.name, MAX(m.parameter_size) AS parameter_size, MAX(m.quantization) AS quantization, MIN(s.name) AS server, MIN(s.status) AS status')
            ->join('servers s', 's.id = m.server_id')
            ->where('s.deleted_at', null);
        if ($statuses) $b->where('s.is_enabled', 1)->whereIn('s.status', $statuses);
        return $b
            ->groupBy('m.name')
            ->orderBy('m.name', 'ASC')
            ->get()->getResultArray();
    }
}
