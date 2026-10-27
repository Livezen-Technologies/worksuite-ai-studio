<?php

namespace App\Controllers\Api\Gateway;

use App\Controllers\Api\BaseApiController;
use App\Services\MlModelService;

/**
 * POST /api/v1/classify   {"model": "<name>", "text": "…" | "texts": ["…"], "top": 3}   (API key with the `classify` scope)
 * → {"model": "…", "results": [{"label": "…", "score": 0.93, "scores": [...]}, …]}.  GET /api/v1/classifiers lists them.
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
        $rows = \Config\Database::connect()->table('ml_models')->select('name, kind, labels, metrics, created_at')->where('status', 'ready')->orderBy('name')->get()->getResultArray();
        foreach ($rows as &$r) { $r['labels'] = json_decode((string) $r['labels'], true) ?: []; $r['metrics'] = json_decode((string) $r['metrics'], true) ?: null; }
        return $this->ok($rows);
    }

    public function classify()
    {
        if (! $this->allowed()) return $this->failure("This key lacks the 'classify' scope.", 403);
        $in = $this->input();
        $m = \Config\Database::connect()->table('ml_models')->where('name', (string) ($in['model'] ?? ''))->where('status', 'ready')->get()->getRowArray();
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
}
