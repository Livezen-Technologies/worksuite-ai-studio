<?php

namespace App\Controllers\Api;

use App\Services\AnalyticsService;

/**
 * Analytics API.  GET /api/analytics?days=30   (analytics.view)
 */
class AnalyticsController extends BaseApiController
{
    public function index()
    {
        if ($r = $this->requirePermission('analytics.view')) return $r;
        $days = max(7, min(365, (int) ($this->request->getGet('days') ?? 30)));
        return $this->ok((new AnalyticsService())->overview($days));
    }
}
