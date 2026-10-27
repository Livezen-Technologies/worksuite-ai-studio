<?php

namespace App\Controllers\Api;

use App\Services\MlModelService;

/**
 * Trained ML models (text classifiers) in the app.
 *   GET    /api/ml-models                 list with labels and measured quality      (models.view)
 *   POST   /api/ml-models/{id}/predict    {text | texts[], top?} → labels and scores  (chat.use)
 *   DELETE /api/ml-models/{id}            remove the model and its files               (models.manage)
 */
class MlModelsController extends BaseApiController
{
    private function db() { return \Config\Database::connect(); }

    public function index()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $rows = $this->db()->table('ml_models m')->select('m.*, d.name AS dataset_name')
            ->join('datasets d', 'd.id = m.dataset_id', 'left')->orderBy('m.id', 'DESC')->get()->getResultArray();
        foreach ($rows as &$m) {
            $m['labels'] = json_decode((string) $m['labels'], true) ?: [];
            $m['metrics'] = json_decode((string) $m['metrics'], true) ?: null;
            unset($m['path']);
        }
        return $this->ok($rows);
    }

    public function predict($id = null)
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $m = $this->db()->table('ml_models')->where('id', (int) $id)->get()->getRowArray();
        if (! $m) return $this->failure('Model not found.', 404);
        $in = $this->input();
        $texts = isset($in['texts']) && is_array($in['texts']) ? $in['texts'] : [(string) ($in['text'] ?? '')];
        try {
            return $this->ok(['model' => $m['name'], 'results' => (new MlModelService())->classify($m, $texts, (int) ($in['top'] ?? 3))]);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 503);
        }
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $m = $this->db()->table('ml_models')->where('id', (int) $id)->get()->getRowArray();
        if (! $m) return $this->failure('Model not found.', 404);
        if (str_starts_with((string) $m['path'], MlModelService::ROOT . '/')) exec('rm -rf ' . escapeshellarg($m['path']));
        $this->db()->table('ml_models')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Model deleted.');
    }
}
