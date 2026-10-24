<?php

namespace App\Controllers\Api;

use App\Services\MonitoringService;

/**
 * Dashboard API — headline stats and chart series.
 *
 *   GET /api/dashboard          stats + charts in one call
 *   GET /api/dashboard/stats    just the stat cards
 *   GET /api/dashboard/charts   just the chart series
 */
class DashboardController extends BaseApiController
{
    public function index()
    {
        if ($r = $this->requirePermission('dashboard.view')) return $r;
        $svc = new MonitoringService();
        return $this->ok(['stats' => $svc->stats(), 'charts' => $svc->charts()]);
    }

    public function stats()
    {
        if ($r = $this->requirePermission('dashboard.view')) return $r;
        return $this->ok((new MonitoringService())->stats());
    }

    public function charts()
    {
        if ($r = $this->requirePermission('dashboard.view')) return $r;
        return $this->ok((new MonitoringService())->charts());
    }
}
