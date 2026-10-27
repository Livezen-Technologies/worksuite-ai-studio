<?php

namespace App\Controllers\Api;

use App\Models\DatasetModel;
use App\Models\DatasetItemModel;
use App\Services\DatasetService;

/**
 * Dataset Manager — instruction datasets for SinLama fine-tuning.
 *
 *   GET    /api/datasets                 list
 *   POST   /api/datasets                 create (+ optional file upload)
 *   GET    /api/datasets/{id}            meta + paginated items (?page=&q=)
 *   POST   /api/datasets/{id}/upload     append a file's rows
 *   POST   /api/datasets/{id}/dedupe     remove exact duplicates
 *   POST   /api/datasets/{id}/clean      normalise whitespace, drop empties
 *   GET    /api/datasets/{id}/export     download Alpaca JSONL
 *   DELETE /api/datasets/{id}            delete dataset (+items)
 *   DELETE /api/datasets/items/{itemId}  delete one row
 */
class DatasetsController extends BaseApiController
{
    private function datasets(): DatasetModel { return new DatasetModel(); }
    private function items(): DatasetItemModel { return new DatasetItemModel(); }

    public function index()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        return $this->ok($this->datasets()->orderBy('id', 'DESC')->findAll());
    }

    public function create()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        // Multipart (file + fields) or plain JSON. NB: input() throws on a
        // multipart body (getJSON), so only fall back to it when POST is empty.
        $in = $this->request->getPost();
        if (! $in) {
            try { $in = $this->input(); } catch (\Throwable) { $in = []; }
        }
        $name     = trim((string) ($in['name'] ?? ''));
        $language = trim((string) ($in['language'] ?? 'si'));
        $desc     = trim((string) ($in['description'] ?? ''));
        if ($name === '') return $this->failure('Dataset name is required.', 422);

        $id = $this->datasets()->insert([
            'name' => $name, 'language' => $language ?: 'si',
            'description' => $desc ?: null, 'created_by' => $this->userId(),
        ], true);

        $result = ['imported' => 0, 'skipped' => 0];
        $file = $this->request->getFile('file');
        if ($file && $file->isValid()) {
            try {
                $result = (new DatasetService())->import(
                    (int) $id,
                    file_get_contents($file->getTempName()),
                    $file->getClientExtension() ?: 'jsonl'
                );
            } catch (\Throwable $e) {
                return $this->failure('Dataset created but import failed: ' . $e->getMessage(), 422, ['dataset_id' => $id]);
            }
        }
        return $this->ok(
            ['dataset' => $this->datasets()->find($id)] + $result,
            "Dataset created ({$result['imported']} rows imported).",
            201
        );
    }

    public function show($id = null)
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $ds = $this->datasets()->find((int) $id);
        if (! $ds) return $this->failure('Dataset not found.', 404);

        $page  = max(1, (int) ($this->request->getGet('page') ?? 1));
        $per   = 25;
        $q     = trim((string) ($this->request->getGet('q') ?? ''));
        $b     = $this->items()->where('dataset_id', (int) $id);
        if ($q !== '') {
            $b = $b->groupStart()->like('instruction', $q)->orLike('output', $q)->groupEnd();
        }
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('id', 'ASC')->findAll($per, ($page - 1) * $per);

        // Engine Splits tab (FR TRNPIPE 003): per-split counts and group_key coverage.
        $db     = \Config\Database::connect();
        $splits = array_column($db->table('dataset_items')->select('split, COUNT(*) AS n')->where('dataset_id', (int) $id)
            ->groupBy('split')->get()->getResultArray(), 'n', 'split');
        $all     = array_sum(array_map('intval', $splits));
        $grouped = $db->table('dataset_items')->where('dataset_id', (int) $id)->where('group_key IS NOT NULL')->where("group_key <> ''")->countAllResults();

        return $this->ok([
            'dataset' => $ds,
            'split_counts'   => [
                'train' => (int) ($splits['train'] ?? 0), 'dev' => (int) ($splits['dev'] ?? 0),
                'test'  => (int) ($splits['test'] ?? 0),  'unassigned' => (int) ($splits[''] ?? 0),
            ],
            'group_coverage' => ['with' => $grouped, 'without' => $all - $grouped],
            'items'   => $rows,
            'total'   => $total,
            'page'    => $page,
            'per'     => $per,
        ]);
    }

    public function upload($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->datasets()->find((int) $id)) return $this->failure('Dataset not found.', 404);
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) return $this->failure('No file received.', 422);
        try {
            $result = (new DatasetService())->import(
                (int) $id, file_get_contents($file->getTempName()), $file->getClientExtension() ?: 'jsonl'
            );
        } catch (\Throwable $e) {
            return $this->failure('Import failed: ' . $e->getMessage(), 422);
        }
        return $this->ok($result + ['dataset' => $this->datasets()->find((int) $id)],
            "{$result['imported']} rows imported, {$result['skipped']} skipped.");
    }

    /** POST /api/datasets/{id}/speech — a zip of recordings + transcripts (see SpeechDatasetService). */
    public function speechUpload($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->datasets()->find((int) $id)) return $this->failure('Dataset not found.', 404);
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) return $this->failure('No zip received.', 422);
        @set_time_limit(0);
        try {
            $r = (new \App\Services\SpeechDatasetService())->importZip((int) $id, $file->getTempName());
        } catch (\Throwable $e) {
            return $this->failure('Import failed: ' . $e->getMessage(), 422);
        }
        return $this->ok($r + ['dataset' => $this->datasets()->find((int) $id)],
            "{$r['imported']} recordings imported" . ($r['skipped'] ? ", {$r['skipped']} skipped" : '') . '.');
    }

    public function dedupe($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->datasets()->find((int) $id)) return $this->failure('Dataset not found.', 404);
        $removed = (new DatasetService())->dedupe((int) $id);
        return $this->ok(['removed' => $removed, 'dataset' => $this->datasets()->find((int) $id)], "{$removed} duplicate rows removed.");
    }

    public function clean($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->datasets()->find((int) $id)) return $this->failure('Dataset not found.', 404);
        $removed = (new DatasetService())->clean((int) $id);
        return $this->ok(['removed' => $removed, 'dataset' => $this->datasets()->find((int) $id)], "Clean complete ({$removed} empty rows removed).");
    }

    /** Stream the dataset as an Alpaca-format JSONL download. */
    public function export($id = null)
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $ds = $this->datasets()->find((int) $id);
        if (! $ds) return $this->failure('Dataset not found.', 404);

        $slug = preg_replace('/[^a-z0-9_-]+/i', '-', $ds['name']) ?: 'dataset';
        while (ob_get_level() > 0) { ob_end_flush(); }
        header('Content-Type: application/jsonl; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$slug}.jsonl\"");
        header('Cache-Control: no-cache');
        foreach ((new DatasetService())->exportJsonl((int) $id) as $line) {
            echo $line;
        }
        exit;
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->datasets()->find((int) $id)) return $this->failure('Dataset not found.', 404);
        $this->items()->where('dataset_id', (int) $id)->delete();
        $this->datasets()->delete((int) $id);
        return $this->ok(null, 'Dataset deleted.');
    }

    public function deleteItem($itemId = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $item = $this->items()->find((int) $itemId);
        if (! $item) return $this->failure('Row not found.', 404);
        $this->items()->delete((int) $itemId);
        (new DatasetService())->recount((int) $item['dataset_id']);
        return $this->ok(null, 'Row deleted.');
    }
}
