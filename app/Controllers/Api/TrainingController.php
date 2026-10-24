<?php

namespace App\Controllers\Api;

use App\Models\TrainingJobModel;
use App\Services\TrainingService;

/**
 * Training Manager — LoRA/QLoRA fine-tune jobs.
 *
 *   GET    /api/training                 list jobs (+dataset info) + base-model suggestions
 *   POST   /api/training                 create job
 *   PUT    /api/training/{id}            update (name/hyperparams/status/notes/logs)
 *   DELETE /api/training/{id}            delete
 *   POST   /api/training/{id}/kit        generate the training kit zip
 *   GET    /api/training/{id}/kit        download the kit zip
 *
 * The kit runs on an external GPU (this server is CPU-only); the trained GGUF
 * comes back in through Model Studio.
 */
class TrainingController extends BaseApiController
{
    private function jobs(): TrainingJobModel { return new TrainingJobModel(); }

    public function index()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        return $this->ok([
            'jobs'        => $this->jobs()->listWithDataset(),
            'base_models' => TrainingService::BASE_MODELS,
            'defaults'    => TrainingService::DEFAULT_HYPERPARAMS,
        ]);
    }

    public function create()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $in = $this->input();
        $name = trim((string) ($in['name'] ?? ''));
        $base = trim((string) ($in['base_model'] ?? ''));
        if ($name === '' || $base === '') {
            return $this->failure('name and base_model are required.', 422);
        }
        $id = $this->jobs()->insert([
            'name'        => $name,
            'dataset_id'  => (int) ($in['dataset_id'] ?? 0) ?: null,
            'base_model'  => $base,
            'method'      => in_array($in['method'] ?? '', ['lora', 'qlora'], true) ? $in['method'] : 'qlora',
            'hyperparams' => json_encode(
                array_intersect_key((array) ($in['hyperparams'] ?? []), TrainingService::DEFAULT_HYPERPARAMS)
            ),
            'notes'       => $in['notes'] ?? null,
            'created_by'  => $this->userId(),
        ], true);
        return $this->ok($this->jobs()->find($id), 'Training job created.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $job = $this->jobs()->find((int) $id);
        if (! $job) return $this->failure('Job not found.', 404);

        $in  = $this->input();
        $upd = [];
        foreach (['name', 'base_model', 'notes', 'logs'] as $f) {
            if (array_key_exists($f, $in)) $upd[$f] = $in[$f];
        }
        if (isset($in['dataset_id'])) $upd['dataset_id'] = (int) $in['dataset_id'] ?: null;
        if (isset($in['method']) && in_array($in['method'], ['lora', 'qlora'], true)) $upd['method'] = $in['method'];
        if (isset($in['status']) && in_array($in['status'], ['draft', 'kit_ready', 'running', 'completed', 'failed', 'imported'], true)) {
            $upd['status'] = $in['status'];
        }
        if (isset($in['hyperparams']) && is_array($in['hyperparams'])) {
            $upd['hyperparams'] = json_encode(
                array_intersect_key($in['hyperparams'], TrainingService::DEFAULT_HYPERPARAMS)
            );
        }
        if ($upd) $this->jobs()->update((int) $id, $upd);
        return $this->ok($this->jobs()->find((int) $id), 'Job updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->jobs()->find((int) $id)) return $this->failure('Job not found.', 404);
        $this->jobs()->delete((int) $id);
        return $this->ok(null, 'Job deleted.');
    }

    public function generateKit($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        try {
            (new TrainingService())->generateKit((int) $id);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }
        return $this->ok($this->jobs()->find((int) $id), 'Training kit generated.');
    }

    public function downloadKit($id = null)
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $path = (new TrainingService())->kitPath((int) $id);
        if (! $path) return $this->failure('Kit not generated yet.', 404);
        return $this->response->download($path, null)->setFileName('sinlama-training-kit-' . (int) $id . '.zip');
    }
}
