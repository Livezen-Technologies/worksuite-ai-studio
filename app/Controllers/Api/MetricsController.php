<?php

namespace App\Controllers\Api;

use App\Services\MonitoringService;
use App\Services\ServerService;

/**
 * Monitoring API.
 *
 *   GET /api/metrics    live per-node system/GPU metrics (Prometheus-backed)
 *   GET /api/health     cluster health summary (re-probes nothing; reads state)
 */
class MetricsController extends BaseApiController
{
    public function index()
    {
        if ($r = $this->requirePermission('monitoring.view')) return $r;
        $svc = new MonitoringService();
        return $this->ok([
            'nodes'           => $svc->nodeMetrics(),
            'prometheus'      => $svc->promReachable(),
        ]);
    }

    public function health()
    {
        if ($r = $this->requirePermission('monitoring.view')) return $r;
        $db = \Config\Database::connect();
        $servers = $db->table('servers')->where('deleted_at', null)->get()->getResultArray();
        $summary = [
            'total'    => count($servers),
            'online'   => 0, 'degraded' => 0, 'offline' => 0, 'unknown' => 0,
            'avg_score'=> 0,
        ];
        $scoreSum = 0;
        foreach ($servers as $s) {
            $summary[$s['status']] = ($summary[$s['status']] ?? 0) + 1;
            $scoreSum += (int) $s['health_score'];
        }
        $summary['avg_score'] = $servers ? (int) round($scoreSum / count($servers)) : 0;
        return $this->ok($summary);
    }

    /** Run a health check across all nodes (manual refresh button). */
    public function refresh()
    {
        if ($r = $this->requirePermission('monitoring.view')) return $r;
        $svc = new ServerService();
        $db  = \Config\Database::connect();
        $servers = $db->table('servers')->where('deleted_at', null)->get()->getResultArray();
        $results = [];
        foreach ($servers as $s) {
            $results[] = ['server_id' => $s['id'], 'name' => $s['name']] + $svc->check($s);
        }
        return $this->ok($results, 'All nodes checked.');
    }
}
