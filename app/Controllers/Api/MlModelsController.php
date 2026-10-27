<?php

namespace App\Controllers\Api;

use App\Services\MlModelService;

/**
 * Trained ML models (text classifiers) in the app.
 *   GET    /api/ml-models                 list with labels and measured quality      (models.view)
 *   POST   /api/ml-models/{id}/predict    {text | texts[], top?} → labels and scores  (chat.use)
 *   POST   /api/ml-models/table           new table model: multipart file (CSV), name, target, task   (models.manage)
 *   DELETE /api/ml-models/{id}            remove the model and its files               (models.manage)
 * Table models (part 4) predict from rows: {row | rows[], top?} → value (+ class scores).
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
            $m['schema'] = json_decode((string) ($m['schema'] ?? ''), true) ?: null;
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
        if ($m['kind'] === 'table') {
            if ($m['status'] !== 'ready') return $this->failure('This model is not ready.', 409);
            $rows = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [is_array($in['row'] ?? null) ? $in['row'] : []];
            try { return $this->ok(['model' => $m['name'], 'results' => (new MlModelService())->predictTable($m, $rows, (int) ($in['top'] ?? 3))]); }
            catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
            catch (\Throwable $e) { return $this->failure($e->getMessage(), 503); }
        }
        $texts = isset($in['texts']) && is_array($in['texts']) ? $in['texts'] : [(string) ($in['text'] ?? '')];
        try {
            return $this->ok(['model' => $m['name'], 'results' => (new MlModelService())->classify($m, $texts, (int) ($in['top'] ?? 3))]);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 503);
        }
    }

    public function createTable()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $f = $this->request->getFile('file');
        if (! $f || ! $f->isValid()) return $this->failure('Choose a CSV file.', 422);
        if ($f->getSize() > 200 * 1048576) return $this->failure('The file is over 200 MB. Upload a sample, or leave unneeded columns out.', 422);
        if (! in_array(strtolower($f->getClientExtension()), ['csv', 'tsv', 'txt'], true)) return $this->failure('Upload a CSV file (export it from Excel or Sheets as CSV).', 422);
        $tmp = WRITEPATH . 'uploads/table-' . bin2hex(random_bytes(6)) . '.csv';
        $f->move(dirname($tmp), basename($tmp));
        try {
            $m = (new MlModelService())->createTable((string) $this->request->getPost('name'), $tmp, (string) $this->request->getPost('target'),
                (string) ($this->request->getPost('task') ?: 'auto'), $this->userId());
        } catch (\InvalidArgumentException $e) { @unlink($tmp); return $this->failure($e->getMessage(), 422); }
        catch (\RuntimeException $e) { @unlink($tmp); return $this->failure($e->getMessage(), 409); }
        unset($m['path']);
        return $this->ok($m, 'Training started. It takes from seconds to a few minutes.', 201);
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
