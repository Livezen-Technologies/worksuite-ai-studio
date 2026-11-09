<?php

namespace App\Controllers\Api;

use App\Services\ModelRegistryService;

/**
 * AI Model Registry API.
 *
 *   GET    /api/registry                         entries + deployments + 24 h performance   (models.view)
 *   GET    /api/registry/meta                    types, capabilities, tasks, roles           (models.view)
 *   POST   /api/registry                         register a model                            (models.manage)
 *   POST   /api/registry/discover                register every installed model not yet listed (models.manage)
 *   PUT    /api/registry/{id}                    update metadata, access, load policy        (models.manage)
 *   DELETE /api/registry/{id}                    remove from the registry (copies stay)      (models.manage)
 *   POST   /api/registry/{id}/deploy             {server_ids:[], force?} assign to nodes     (models.manage)
 *   DELETE /api/registry/{id}/deploy/{server}    remove from one node                        (models.manage)
 *   POST   /api/registry/{id}/update             re-pull on every assigned node              (models.manage)
 *   GET    /api/registry/catalogue               curated models (LLM, embedding, vision, speech…) + registered/installed (models.view)
 *   POST   /api/registry/catalogue               {id, engine, server_ids?} register a catalogue model, deploy optionally (models.manage)
 *   GET    /api/registry/defaults                default model per task / application        (models.view)
 *   PUT    /api/registry/defaults                {defaults:[{task, application?, model_name}]} (models.manage)
 */
class ModelRegistryController extends BaseApiController
{
    private function svc(): ModelRegistryService { return new ModelRegistryService(); }

    public function index()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        return $this->ok($this->svc()->all());
    }

    public function meta()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $roles = array_column(\Config\Database::connect()->table('roles')->select('name')->orderBy('id')->get()->getResultArray(), 'name');
        return $this->ok([
            'types' => ModelRegistryService::TYPES, 'capabilities' => ModelRegistryService::CAPABILITIES,
            'tasks' => ModelRegistryService::TASKS, 'roles' => $roles,
        ]);
    }

    public function create()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $in   = $this->input();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) return $this->failure('A model name is required.', 422, ['name' => 'Required.']);
        $svc = $this->svc();
        if ($svc->findByName($name)) return $this->failure('That model is already in the registry.', 409, ['name' => 'Already registered.']);
        $guess  = ModelRegistryService::infer($name);
        $fields = $svc->fields($in) + ['type' => $guess['type'], 'capabilities' => json_encode($guess['capabilities'])];
        $now    = date('Y-m-d H:i:s');
        $db     = \Config\Database::connect();
        $db->table('model_registry')->insert(['name' => $name, 'created_by' => $this->userId(), 'created_at' => $now, 'updated_at' => $now] + $fields);
        $id = (int) $db->insertID();
        $msg = 'Model registered.';
        if (! empty($in['server_ids']) && is_array($in['server_ids'])) {
            $res = $svc->deploy($svc->find($id), $in['server_ids']);
            $msg .= $this->deployMessage($res);
        }
        return $this->ok($this->row($id), $msg, 201);
    }

    public function discover()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $n = $this->svc()->discover($this->userId());
        return $this->ok(['added' => $n], $n ? "Registered {$n} installed model" . ($n > 1 ? 's' : '') . '.' : 'Every installed model is already registered.');
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $svc = $this->svc();
        if (! $svc->find((int) $id)) return $this->failure('Model not found.', 404);
        $fields = $svc->fields($this->input());
        if ($fields) \Config\Database::connect()->table('model_registry')->where('id', (int) $id)->update($fields + ['updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok($this->row((int) $id), 'Model updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        if (! $this->svc()->find((int) $id)) return $this->failure('Model not found.', 404);
        $db = \Config\Database::connect();
        $db->table('model_assignments')->where('registry_id', (int) $id)->delete();
        $db->table('model_registry')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Removed from the registry. Copies on nodes were not deleted.');
    }

    public function deploy($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $svc = $this->svc();
        if (! ($m = $svc->find((int) $id))) return $this->failure('Model not found.', 404);
        if ($m['engine'] === 'speech') return $this->failure('Speech models run on the speech service and reach GPU nodes with their speech sync; there is nothing to deploy.', 422);
        $in  = $this->input();
        $ids = is_array($in['server_ids'] ?? null) ? $in['server_ids'] : [];
        if (! $ids) return $this->failure('Choose at least one node.', 422);
        $res = $svc->deploy($m, $ids, ! empty($in['force']));
        if (! $res['queued'] && ! $res['recorded']) {
            return $this->failure('Not deployed: ' . implode(' ', $res['skipped']), 422, ['skipped' => $res['skipped']]);
        }
        return $this->ok($res + ['model' => $this->row((int) $id)], trim('Deployment started.' . $this->deployMessage($res)));
    }

    public function undeploy($id = null, $serverId = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $svc = $this->svc();
        if (! ($m = $svc->find((int) $id))) return $this->failure('Model not found.', 404);
        try {
            $svc->undeploy($m, (int) $serverId);
        } catch (\Throwable $e) {
            return $this->failException($e, 'The node could not remove the model.', 502);
        }
        return $this->ok($this->row((int) $id), 'Model removed from the node.');
    }

    public function refresh($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $svc = $this->svc();
        if (! ($m = $svc->find((int) $id))) return $this->failure('Model not found.', 404);
        $res = $svc->update($m);
        return $this->ok($res, $res['queued'] ? 'Update queued on ' . implode(', ', $res['queued']) . '.' : 'No Ollama node to update; other engines update on their own.');
    }

    public function catalogue()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        return $this->ok($this->svc()->catalogue());
    }

    public function addFromCatalogue()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $in  = $this->input();
        $svc = $this->svc();
        try {
            $row = $svc->addFromCatalogue((string) ($in['id'] ?? ''), (string) ($in['engine'] ?? 'ollama'), $this->userId());
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
        $msg = 'Added to the registry.';
        if (! empty($in['server_ids']) && is_array($in['server_ids']) && $row['engine'] !== 'speech') {
            $res = $svc->deploy($row, $in['server_ids']);
            $msg .= $this->deployMessage($res);
        }
        return $this->ok($this->row((int) $row['id']), $msg, 201);
    }

    public function defaults()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        return $this->ok($this->svc()->defaults());
    }

    public function saveDefaults()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $rows = $this->input()['defaults'] ?? null;
        if (! is_array($rows)) return $this->failure('defaults[] is required.', 422);
        return $this->ok($this->svc()->saveDefaults($rows), 'Default models saved.');
    }

    private function row(int $id): ?array
    {
        foreach ($this->svc()->all() as $r) if ((int) $r['id'] === $id) return $r;
        return null;
    }

    private function deployMessage(array $res): string
    {
        $parts = [];
        if ($res['queued'])   $parts[] = ' Downloading on ' . implode(', ', $res['queued']) . '.';
        if ($res['recorded']) $parts[] = ' Assigned to ' . implode(', ', $res['recorded']) . ' (that engine must serve the model; it shows as deployed once it does).';
        foreach ($res['skipped'] as $node => $why) $parts[] = " Skipped {$node}: {$why}";
        return implode('', $parts);
    }
}
