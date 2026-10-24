<?php

namespace App\Controllers\Api;

use App\Models\ApiLogModel;

/**
 * API request logs viewer.
 *
 *   GET /api/apilogs?api_key_id=&model=&status=&since=&page=&per_page=
 */
class ApiLogsController extends BaseApiController
{
    public function index()
    {
        if ($r = $this->requirePermission('logs.view')) return $r;
        [$page, $per, $offset] = $this->paging(30);

        $filters = [
            'api_key_id' => $this->request->getGet('api_key_id'),
            'model'      => $this->request->getGet('model'),
            'status'     => $this->request->getGet('status'),
            'since'      => $this->sinceFromRange($this->request->getGet('range')),
        ];
        $res = (new ApiLogModel())->feed($filters, $per, $offset);

        return $this->ok([
            'rows'     => $res['rows'],
            'total'    => $res['total'],
            'page'     => $page,
            'per_page' => $per,
        ]);
    }

    private function sinceFromRange(?string $range): ?string
    {
        return match ($range) {
            'today' => date('Y-m-d 00:00:00'),
            'week'  => date('Y-m-d H:i:s', strtotime('-7 days')),
            'month' => date('Y-m-d H:i:s', strtotime('-30 days')),
            default => null,
        };
    }
}
