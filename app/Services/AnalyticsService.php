<?php

namespace App\Services;

/**
 * AnalyticsService — deeper usage analytics aggregated from chat_messages and
 * api_logs (gateway traffic). Also produces the daily usage_statistics rollup.
 */
class AnalyticsService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function overview(int $days = 30): array
    {
        return [
            'daily_requests'  => $this->dailyRequests($days),
            'token_usage'     => $this->dailyTokens($days),
            'model_popularity'=> $this->modelPopularity(),
            'response_time'   => $this->responseTimeByDay($days),
            'gateway_status'  => $this->gatewayStatusBreakdown(),
            'totals'          => $this->totals($days),
        ];
    }

    /**
     * Unified activity: Playground assistant turns + gateway requests, normalised
     * to (created_at, model, pt, ct, ms). All request/token/model/latency metrics
     * read from this so both sources are counted.
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

    private function dailyRequests(int $days): array
    {
        $rows = $this->db->query(
            "SELECT DATE(created_at) d, COUNT(*) n FROM ({$this->events()}) e
             WHERE created_at >= CURDATE() - INTERVAL ? DAY GROUP BY d ORDER BY d", [$days]
        )->getResultArray();
        return array_map(static fn ($r) => ['t' => $r['d'], 'requests' => (int) $r['n']], $rows);
    }

    private function dailyTokens(int $days): array
    {
        $rows = $this->db->query(
            "SELECT DATE(created_at) d, COALESCE(SUM(pt),0) p, COALESCE(SUM(ct),0) c
             FROM ({$this->events()}) e WHERE created_at >= CURDATE() - INTERVAL ? DAY GROUP BY d ORDER BY d", [$days]
        )->getResultArray();
        return array_map(static fn ($r) => ['t' => $r['d'], 'prompt' => (int) $r['p'], 'completion' => (int) $r['c']], $rows);
    }

    private function modelPopularity(): array
    {
        $rows = $this->db->query(
            "SELECT model m, COUNT(*) n FROM ({$this->events()}) e GROUP BY m ORDER BY n DESC LIMIT 10"
        )->getResultArray();
        return array_map(static fn ($r) => ['model' => $r['m'], 'requests' => (int) $r['n']], $rows);
    }

    private function responseTimeByDay(int $days): array
    {
        $rows = $this->db->query(
            "SELECT DATE(created_at) d, ROUND(AVG(ms)) ms FROM ({$this->events()}) e
             WHERE ms IS NOT NULL AND created_at >= CURDATE() - INTERVAL ? DAY
             GROUP BY d ORDER BY d", [$days]
        )->getResultArray();
        return array_map(static fn ($r) => ['t' => $r['d'], 'ms' => (int) $r['ms']], $rows);
    }

    private function gatewayStatusBreakdown(): array
    {
        if (! $this->db->tableExists('api_logs')) return [];
        $rows = $this->db->query(
            "SELECT CASE WHEN status < 300 THEN 'success' WHEN status < 500 THEN 'client error' ELSE 'server error' END label,
             COUNT(*) n FROM api_logs GROUP BY label"
        )->getResultArray();
        return array_map(static fn ($r) => ['label' => $r['label'], 'value' => (int) $r['n']], $rows);
    }

    private function totals(int $days): array
    {
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $row = $this->db->query(
            "SELECT COUNT(*) reqs, COALESCE(SUM(pt+ct),0) toks FROM ({$this->events()}) e WHERE created_at >= ?", [$since]
        )->getRowArray();
        return [
            'requests'   => (int) ($row['reqs'] ?? 0),
            'tokens'     => (int) ($row['toks'] ?? 0),
            'gateway'    => $this->db->tableExists('api_logs') ? $this->db->table('api_logs')->where('created_at >=', $since)->countAllResults() : 0,
            'active_users' => $this->db->table('chat_sessions')->where('updated_at >=', $since)->select('user_id')->distinct()->countAllResults(),
        ];
    }

    /** Roll up today's per-model usage into usage_statistics (idempotent upsert). */
    public function report(): array
    {
        $today = date('Y-m-d');
        $rows = $this->db->query(
            "SELECT COALESCE(model,'unknown') m, COUNT(*) reqs,
             COALESCE(SUM(prompt_tokens),0) pt, COALESCE(SUM(completion_tokens),0) ct, ROUND(AVG(total_ms)) ms
             FROM chat_messages WHERE role='assistant' AND DATE(created_at)=? GROUP BY m", [$today]
        )->getResultArray();
        foreach ($rows as $r) {
            $data = ['day' => $today, 'model' => $r['m'], 'requests' => (int) $r['reqs'],
                'prompt_tokens' => (int) $r['pt'], 'completion_tokens' => (int) $r['ct'], 'avg_ms' => (int) $r['ms']];
            $existing = $this->db->table('usage_statistics')->where('day', $today)->where('model', $r['m'])->get()->getRowArray();
            $existing
                ? $this->db->table('usage_statistics')->where('id', $existing['id'])->update($data)
                : $this->db->table('usage_statistics')->insert($data);
        }
        return ['day' => $today, 'models_rolled_up' => count($rows)];
    }
}
