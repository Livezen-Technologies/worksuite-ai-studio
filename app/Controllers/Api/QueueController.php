<?php

namespace App\Controllers\Api;

use App\Services\QueueService;

/**
 * Queue Manager API.
 *
 *   GET    /api/queue            list jobs (filter by status) + stats  (queues.view)
 *   POST   /api/queue            enqueue a job                          (queues.manage)
 *   POST   /api/queue/{id}/cancel  cancel a pending job                 (queues.manage)
 *   POST   /api/queue/{id}/retry   requeue a failed/cancelled job       (queues.manage)
 *   POST   /api/queue/work         process pending jobs now             (queues.manage)
 */
class QueueController extends BaseApiController
{
    private function table() { return \Config\Database::connect()->table('queues'); }

    public function index()
    {
        if ($r = $this->requirePermission('queues.view')) return $r;
        [$page, $per, $offset] = $this->paging(30);
        $b = $this->table();
        if ($s = $this->request->getGet('status')) $b->where('status', $s);
        if ($t = $this->request->getGet('type'))   $b->where('type', $t);
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('id', 'DESC')->limit($per, $offset)->get()->getResultArray();
        return $this->ok([
            'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per,
            'stats' => (new QueueService())->stats(),
        ]);
    }

    public function create()
    {
        if ($r = $this->requirePermission('queues.manage')) return $r;
        $in = $this->input();
        $type = trim((string) ($in['type'] ?? ''));
        if ($type === '') return $this->failure('A job type is required.', 422);
        $id = (new QueueService())->enqueue($type, $in['payload'] ?? [], (int) ($in['priority'] ?? 5));
        return $this->ok(['id' => $id], 'Job enqueued.', 201);
    }

    public function cancel($id = null)
    {
        if ($r = $this->requirePermission('queues.manage')) return $r;
        $job = $this->table()->where('id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);
        if (! in_array($job['status'], ['pending', 'running'], true)) {
            return $this->failure('Only pending or running jobs can be cancelled.', 422);
        }
        $this->table()->where('id', (int) $id)->update(['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Job cancelled.');
    }

    public function retry($id = null)
    {
        if ($r = $this->requirePermission('queues.manage')) return $r;
        $job = $this->table()->where('id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);
        if (! in_array($job['status'], ['failed', 'cancelled'], true)) {
            return $this->failure('Only failed or cancelled jobs can be retried.', 422);
        }
        $this->table()->where('id', (int) $id)->update([
            'status' => 'pending', 'attempts' => 0, 'error' => null, 'result' => null,
            'available_at' => date('Y-m-d H:i:s'), 'started_at' => null, 'finished_at' => null,
        ]);
        return $this->ok(null, 'Job requeued.');
    }

    public function work()
    {
        if ($r = $this->requirePermission('queues.manage')) return $r;
        return $this->ok((new QueueService())->work(25), 'Worker run complete.');
    }
}
