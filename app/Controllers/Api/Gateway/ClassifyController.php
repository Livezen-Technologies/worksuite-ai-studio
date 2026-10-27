<?php

namespace App\Controllers\Api\Gateway;

use App\Controllers\Api\BaseApiController;
use App\Services\MlModelService;

/**
 * POST /api/v1/classify   {"model": "<name>", "text": "…" | "texts": ["…"], "top": 3}   (API key with the `classify` scope)
 * → {"model": "…", "results": [{"label": "…", "score": 0.93, "scores": [...]}, …]}.  GET /api/v1/classifiers lists them.
 * POST /api/v1/predict    {"model": "<table model>", "row": {"column": value, …} | "rows": [...], "top": 3}   (same scope)
 * → {"model": "…", "results": [{"value": …, "score"?: …, "scores"?: [...]}]}  — table models (part 4).
 */
class ClassifyController extends BaseApiController
{
    private function allowed(): bool
    {
        return in_array('classify', explode(',', (string) (($_SERVER['ohub_api_key'] ?? [])['scopes'] ?? '')), true);
    }

    public function index()
    {
        if (! $this->allowed()) return $this->failure("This key lacks the 'classify' scope.", 403);
        $rows = \Config\Database::connect()->table('ml_models')->select('name, kind, task, target, labels, metrics, schema, created_at')->where('status', 'ready')->orderBy('name')->get()->getResultArray();
        foreach ($rows as &$r) { $r['labels'] = json_decode((string) $r['labels'], true) ?: []; $r['metrics'] = json_decode((string) $r['metrics'], true) ?: null;
            $sc = json_decode((string) $r['schema'], true); $r['inputs'] = $sc ? array_map(fn ($f) => ['name' => $f['name'], 'type' => $f['type']], $sc['features']) : null; unset($r['schema']);
            if ($r['kind'] === 'table') unset($r['metrics']['compare'], $r['metrics']['dropped'], $r['metrics']['confusion']); }
        return $this->ok($rows);
    }

    public function classify()
    {
        if (! $this->allowed()) return $this->failure("This key lacks the 'classify' scope.", 403);
        $in = $this->input();
        $m = \Config\Database::connect()->table('ml_models')->where('name', (string) ($in['model'] ?? ''))->where('status', 'ready')->where('kind !=', 'table')->get()->getRowArray();
        if (! $m) return $this->failure('No classifier with that name. GET /api/v1/classifiers lists them.', 404);
        $texts = isset($in['texts']) && is_array($in['texts']) ? $in['texts'] : [(string) ($in['text'] ?? '')];
        try {
            return $this->ok(['model' => $m['name'], 'results' => (new MlModelService())->classify($m, $texts, (int) ($in['top'] ?? 3))]);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 503);
        }
    }

    public function predict()
    {
        if (! $this->allowed()) return $this->failure("This key lacks the 'classify' scope.", 403);
        $in = $this->input();
        $m = \Config\Database::connect()->table('ml_models')->where(['name' => (string) ($in['model'] ?? ''), 'status' => 'ready', 'kind' => 'table'])->get()->getRowArray();
        if (! $m) return $this->failure('No table model with that name. GET /api/v1/classifiers lists them.', 404);
        $rows = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [is_array($in['row'] ?? null) ? $in['row'] : []];
        try {
            return $this->ok(['model' => $m['name'], 'results' => (new MlModelService())->predictTable($m, $rows, (int) ($in['top'] ?? 3))]);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 503);
        }
    }
}
