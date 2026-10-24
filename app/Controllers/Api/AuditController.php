<?php

namespace App\Controllers\Api;

/**
 * Audit log viewer.  GET /api/audit?user_id=&action=&range=&page=&per_page=
 */
class AuditController extends BaseApiController
{
    public function index()
    {
        if ($r = $this->requirePermission('audit.view')) return $r;
        [$page, $per, $offset] = $this->paging(40);
        $db = \Config\Database::connect();

        $b = $db->table('audit_logs');
        if ($uid = $this->request->getGet('user_id'))   $b->where('user_id', (int) $uid);
        if ($action = $this->request->getGet('action')) $b->like('action', $action);
        $range = $this->request->getGet('range');
        $since = match ($range) {
            'today' => date('Y-m-d 00:00:00'),
            'week'  => date('Y-m-d H:i:s', strtotime('-7 days')),
            'month' => date('Y-m-d H:i:s', strtotime('-30 days')),
            default => null,
        };
        if ($since) $b->where('created_at >=', $since);

        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('id', 'DESC')->limit($per, $offset)->get()->getResultArray();
        return $this->ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }
}
