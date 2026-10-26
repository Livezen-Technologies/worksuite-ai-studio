<?php

namespace App\Controllers\Api;

use App\Models\DatasetItemModel;
use App\Models\DatasetModel;
use App\Models\TrainingJobModel;
use App\Services\ComputeAllocationService;
use App\Services\DatasetService;
use App\Services\TrainingService;

/**
 * User portal — a user's own datasets and training jobs, placed on nodes within their allocations.
 *
 *   GET    /api/portal/overview            my allocations (+usage) and recent jobs
 *   GET    /api/portal/nodes               nodes I am allocated, with free capacity
 *   GET    /api/portal/datasets            my datasets + shared ones
 *   POST   /api/portal/datasets            create (multipart: name, language, description, visibility, file?)
 *   GET    /api/portal/datasets/{id}       one dataset + first rows (own or shared)
 *   POST   /api/portal/datasets/{id}/upload  append rows (own only)
 *   DELETE /api/portal/datasets/{id}       delete (own only)
 *   GET    /api/portal/jobs                my jobs with per-node placement
 *   POST   /api/portal/jobs                create job {name, base_model, method, dataset_id, hyperparams, nodes:[{server_id,cpu_cores,gpus}]}
 *   GET    /api/portal/jobs/{id}           one job with placement
 *   PUT    /api/portal/jobs/{id}/nodes     change placement (within allocation; not once running)
 *   POST   /api/portal/jobs/{id}/cancel    cancel all active node placements
 *   POST   /api/portal/jobs/{id}/kit       generate kit;  GET same path downloads it
 *
 * Everything is scoped to created_by = current user. Admin placement/overrides live in ComputeController.
 */
class PortalController extends BaseApiController
{
    private function svc(): ComputeAllocationService { return new ComputeAllocationService(); }
    private function jobs(): TrainingJobModel { return new TrainingJobModel(); }
    private function datasets(): DatasetModel { return new DatasetModel(); }

    private function ownJob($id): ?array
    {
        $job = $this->jobs()->find((int) $id);
        return $job && (int) $job['created_by'] === $this->userId() ? $job : null;
    }

    /** Own dataset, or (when $allowShared) a shared one. */
    private function visibleDataset($id, bool $allowShared = true): ?array
    {
        $ds = $this->datasets()->find((int) $id);
        if (! $ds) return null;
        if ((int) $ds['created_by'] === $this->userId()) return $ds;
        return $allowShared && ($ds['visibility'] ?? 'private') === 'shared' ? $ds : null;
    }

    private function withNodes(array $jobs): array
    {
        $map = $this->svc()->nodesFor(array_map(fn ($j) => (int) $j['id'], $jobs));
        foreach ($jobs as &$j) $j['nodes'] = $map[(int) $j['id']] ?? [];
        return $jobs;
    }

    private function myJobs(?int $limit = null): array
    {
        $b = \Config\Database::connect()->table('training_jobs t')
            ->select('t.*, d.name AS dataset_name, d.item_count AS dataset_items')
            ->join('datasets d', 'd.id = t.dataset_id', 'left')
            ->where('t.created_by', $this->userId())->orderBy('t.id', 'DESC');
        if ($limit) $b->limit($limit);
        return $this->withNodes($b->get()->getResultArray());
    }

    /** Validate a nodes[] payload into [{server_id,cpu_cores,gpus}]. */
    private function nodesInput($raw): array
    {
        $out = [];
        foreach ((array) $raw as $n) {
            if (! is_array($n) || empty($n['server_id'])) continue;
            $out[] = ['server_id' => (int) $n['server_id'], 'cpu_cores' => max(0, (int) ($n['cpu_cores'] ?? 0)), 'gpus' => max(0, (int) ($n['gpus'] ?? 0))];
        }
        return $out;
    }

    public function overview()
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $uid = $this->userId();
        $db  = \Config\Database::connect();
        return $this->ok([
            'allocations' => $this->svc()->allocations($uid),
            'jobs'        => $this->myJobs(10),
            'counts'      => [
                'datasets' => $db->table('datasets')->where('created_by', $uid)->countAllResults(),
                'jobs'     => $db->table('training_jobs')->where('created_by', $uid)->countAllResults(),
                'running'  => $db->table('training_jobs')->where('created_by', $uid)->where('placement_status', 'running')->countAllResults(),
            ],
        ]);
    }

    public function nodes()
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $rows = array_values(array_filter(
            $this->svc()->allocations($this->userId()),
            fn ($a) => ! $a['expired'] && (int) $a['allow_training'] === 1
        ));
        return $this->ok($rows);
    }

    // ---- datasets ----

    public function datasetsIndex()
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $rows = $this->datasets()->groupStart()->where('created_by', $this->userId())->orWhere('visibility', 'shared')->groupEnd()
            ->orderBy('id', 'DESC')->findAll();
        foreach ($rows as &$d) $d['mine'] = (int) $d['created_by'] === $this->userId();
        return $this->ok($rows);
    }

    public function datasetCreate()
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $in = $this->request->getPost();
        if (! $in) {
            try { $in = $this->input(); } catch (\Throwable) { $in = []; }
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return $this->failure('Dataset name is required.', 422);
        $id = $this->datasets()->insert([
            'name'        => $name,
            'language'    => trim((string) ($in['language'] ?? 'si')) ?: 'si',
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'visibility'  => ($in['visibility'] ?? '') === 'shared' ? 'shared' : 'private',
            'created_by'  => $this->userId(),
        ], true);

        $result = ['imported' => 0, 'skipped' => 0];
        $file = $this->request->getFile('file');
        if ($file && $file->isValid()) {
            try {
                $result = (new DatasetService())->import((int) $id, file_get_contents($file->getTempName()), $file->getClientExtension() ?: 'jsonl');
            } catch (\Throwable $e) {
                return $this->failure('Dataset created but import failed: ' . $e->getMessage(), 422, ['dataset_id' => $id]);
            }
        }
        return $this->ok(['dataset' => $this->datasets()->find($id)] + $result, "Dataset created ({$result['imported']} rows imported).", 201);
    }

    public function datasetShow($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $ds = $this->visibleDataset($id);
        if (! $ds) return $this->failure('Dataset not found.', 404);
        $ds['mine'] = (int) $ds['created_by'] === $this->userId();
        $items = (new DatasetItemModel())->where('dataset_id', (int) $id)->orderBy('id', 'ASC')->findAll(25);
        return $this->ok(['dataset' => $ds, 'items' => $ds['mine'] ? $items : array_slice($items, 0, 5)]);
    }

    public function datasetUpload($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        if (! $this->visibleDataset($id, false)) return $this->failure('Dataset not found.', 404);
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) return $this->failure('No file received.', 422);
        try {
            $result = (new DatasetService())->import((int) $id, file_get_contents($file->getTempName()), $file->getClientExtension() ?: 'jsonl');
        } catch (\Throwable $e) {
            return $this->failure('Import failed: ' . $e->getMessage(), 422);
        }
        return $this->ok($result + ['dataset' => $this->datasets()->find((int) $id)], "{$result['imported']} rows imported, {$result['skipped']} skipped.");
    }

    public function datasetDelete($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        if (! $this->visibleDataset($id, false)) return $this->failure('Dataset not found.', 404);
        if ($this->jobs()->where('dataset_id', (int) $id)->countAllResults()) {
            return $this->failure('This dataset is used by a training job; delete the job first.', 409);
        }
        $this->datasets()->delete((int) $id);
        return $this->ok(null, 'Dataset deleted.');
    }

    // ---- jobs ----

    public function jobsIndex()
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        return $this->ok([
            'jobs'        => $this->myJobs(),
            'base_models' => TrainingService::BASE_MODELS,
            'defaults'    => TrainingService::DEFAULT_HYPERPARAMS,
        ]);
    }

    public function jobShow($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $job = $this->ownJob($id);
        if (! $job) return $this->failure('Job not found.', 404);
        $job['nodes'] = $this->svc()->nodesOf((int) $id);
        $job['kit_ready'] = (new TrainingService())->kitPath((int) $id) !== null;
        return $this->ok($job);
    }

    public function jobCreate()
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $in   = $this->input();
        $name = trim((string) ($in['name'] ?? ''));
        $base = trim((string) ($in['base_model'] ?? ''));
        if ($name === '' || $base === '') return $this->failure('name and base_model are required.', 422);
        $dsId = (int) ($in['dataset_id'] ?? 0);
        if ($dsId && ! $this->visibleDataset($dsId)) return $this->failure('Dataset not found.', 404);

        $nodes    = $this->nodesInput($in['nodes'] ?? []);
        $problems = $this->svc()->check($this->userId(), $nodes);
        if ($problems) return $this->failure(implode(' ', $problems), 409, ['problems' => $problems]);

        $db = \Config\Database::connect();
        $db->transStart();
        $id = $this->jobs()->insert([
            'name'        => $name,
            'dataset_id'  => $dsId ?: null,
            'base_model'  => $base,
            'method'      => in_array($in['method'] ?? '', ['lora', 'qlora'], true) ? $in['method'] : 'qlora',
            'hyperparams' => json_encode(array_intersect_key((array) ($in['hyperparams'] ?? []), TrainingService::DEFAULT_HYPERPARAMS)),
            'notes'       => $in['notes'] ?? null,
            'created_by'  => $this->userId(),
        ], true);
        $this->svc()->place((int) $id, $nodes, $this->userId(), false);
        $db->transComplete();
        return $this->jobShow($id)->setStatusCode(201);
    }

    public function jobNodes($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        $job = $this->ownJob($id);
        if (! $job) return $this->failure('Job not found.', 404);
        if (in_array($job['placement_status'], ['running', 'completed', 'partial'], true)) {
            return $this->failure('Placement cannot change once the job has started.', 409);
        }
        $nodes    = $this->nodesInput($this->input()['nodes'] ?? []);
        $problems = $this->svc()->check($this->userId(), $nodes, (int) $id);
        if ($problems) return $this->failure(implode(' ', $problems), 409, ['problems' => $problems]);
        $this->svc()->place((int) $id, $nodes, $this->userId(), false);
        return $this->jobShow($id);
    }

    public function jobCancel($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        if (! $this->ownJob($id)) return $this->failure('Job not found.', 404);
        foreach ($this->svc()->nodesOf((int) $id) as $n) {
            if (in_array($n['status'], ComputeAllocationService::ACTIVE, true)) {
                $this->svc()->setNodeStatus((int) $id, (int) $n['server_id'], 'cancelled', null, 'Cancelled by owner');
            }
        }
        return $this->jobShow($id);
    }

    public function jobKit($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        if (! $this->ownJob($id)) return $this->failure('Job not found.', 404);
        try {
            (new TrainingService())->generateKit((int) $id);
        } catch (\DomainException $e) {
            return $this->failure($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }
        return $this->jobShow($id);
    }

    public function jobKitDownload($id = null)
    {
        if ($r = $this->requirePermission('portal.use')) return $r;
        if (! $this->ownJob($id)) return $this->failure('Job not found.', 404);
        $path = (new TrainingService())->kitPath((int) $id);
        if (! $path) return $this->failure('Kit not generated yet.', 404);
        return $this->response->download($path, null)->setFileName('sinlama-training-kit-' . (int) $id . '.zip');
    }
}
