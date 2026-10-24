<?php

namespace App\Controllers\Api;

use App\Services\SchedulerService;

/**
 * Scheduler API.
 *
 *   GET  /api/cron               list scheduled jobs (seeds defaults)  (queues.view)
 *   PUT  /api/cron/{id}          toggle enabled / change schedule      (queues.manage)
 *   POST /api/cron/{id}/run      run a job now                          (queues.manage)
 */
class CronController extends BaseApiController
{
    private function table() { return \Config\Database::connect()->table('cron_jobs'); }

    public function index()
    {
        if ($r = $this->requirePermission('queues.view')) return $r;
        (new SchedulerService())->seedDefaults();
        return $this->ok($this->table()->orderBy('id', 'ASC')->get()->getResultArray());
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('queues.manage')) return $r;
        $job = $this->table()->where('id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);

        $in = $this->input();
        $data = [];
        if (array_key_exists('enabled', $in)) $data['enabled'] = $in['enabled'] ? 1 : 0;
        if (! empty($in['schedule'])) {
            $data['schedule'] = $in['schedule'];
            $data['next_run_at'] = (new SchedulerService())->nextRun($in['schedule']);
        }
        if ($data) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->table()->where('id', (int) $id)->update($data);
        }
        return $this->ok($this->table()->where('id', (int) $id)->get()->getRowArray(), 'Schedule updated.');
    }

    public function run($id = null)
    {
        if ($r = $this->requirePermission('queues.manage')) return $r;
        $job = $this->table()->where('id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);
        $res = (new SchedulerService())->run($job);
        return $this->ok($res, ($res['ok'] ?? false) ? 'Job ran.' : 'Job failed.');
    }
}
