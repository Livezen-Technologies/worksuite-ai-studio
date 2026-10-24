<?php

namespace App\Controllers\Api;

use App\Models\ModelModel;
use App\Models\ModelConfigModel;
use App\Models\ServerModel;
use App\Services\ModelService;

/**
 * Model Manager API.
 *
 *   GET    /api/models[?server_id=]      installed models across nodes
 *   POST   /api/models/sync              reconcile DB with node(s)   (models.manage)
 *   POST   /api/models/pull              stream a model download      (models.manage)
 *   DELETE /api/models/{id}              remove a model from its node (models.manage)
 *   POST   /api/models/{id}/load|unload  load/unload into memory      (models.manage)
 *   POST   /api/models/{id}/default      mark as cluster default      (models.manage)
 *   GET    /api/models/{id}/config       per-model generation params
 *   PUT    /api/models/{id}/config       save params                  (models.manage)
 */
class ModelsController extends BaseApiController
{
    private function models(): ModelModel { return new ModelModel(); }
    private function servers(): ServerModel { return new ServerModel(); }

    public function index()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $serverId = (int) ($this->request->getGet('server_id') ?? 0) ?: null;
        return $this->ok($this->models()->listWithServer($serverId));
    }

    public function sync()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $svc = new ModelService();
        $serverId = (int) ($this->input()['server_id'] ?? 0);

        if ($serverId) {
            $server = $this->servers()->find($serverId);
            if (! $server) return $this->failure('Server not found.', 404);
            try {
                return $this->ok($svc->syncServer($server), 'Models synced.');
            } catch (\Throwable $e) {
                return $this->failure('Node unreachable: ' . $e->getMessage(), 502);
            }
        }
        return $this->ok($svc->syncAll(), 'Sync complete.');
    }

    /**
     * Stream a model pull as NDJSON progress lines. The client reads the body
     * with fetch() + a ReadableStream reader (EventSource can't send the Bearer
     * token). On completion the node is re-synced so the new model appears.
     */
    public function pull()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $in       = $this->input();
        $serverId = (int) ($in['server_id'] ?? 0);
        $name     = trim((string) ($in['name'] ?? ''));
        if (! $serverId || $name === '') {
            return $this->failure('server_id and model name are required.', 422);
        }
        $server = $this->servers()->find($serverId);
        if (! $server) return $this->failure('Server not found.', 404);

        // Switch to manual NDJSON streaming output.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: application/x-ndjson');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $emit = static function (array $line) {
            echo json_encode($line) . "\n";
            flush();
        };

        $svc = new ModelService();
        try {
            $svc->clientFor($server)->pull($name, $emit);
            $svc->syncServer($server);
            $emit(['status' => 'success', 'done' => true]);
        } catch (\Throwable $e) {
            $emit(['error' => $e->getMessage(), 'done' => true]);
        }
        exit;
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        [$model, $server, $err] = $this->resolve($id);
        if ($err) return $err;

        try {
            (new ModelService())->clientFor($server)->delete($model['name']);
        } catch (\Throwable $e) {
            return $this->failure('Node unreachable: ' . $e->getMessage(), 502);
        }
        $this->models()->delete($model['id']);
        return $this->ok(null, 'Model removed from node.');
    }

    public function load($id = null)   { return $this->memory($id, 'load'); }
    public function unload($id = null) { return $this->memory($id, 'unload'); }

    private function memory($id, string $action)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        [$model, $server, $err] = $this->resolve($id);
        if ($err) return $err;

        try {
            $client = (new ModelService())->clientFor($server);
            $action === 'load' ? $client->load($model['name']) : $client->unload($model['name']);
        } catch (\Throwable $e) {
            return $this->failure('Node unreachable: ' . $e->getMessage(), 502);
        }
        $this->models()->update($model['id'], ['status' => $action === 'load' ? 'running' : 'available']);
        return $this->ok(null, $action === 'load' ? 'Model loaded into memory.' : 'Model unloaded.');
    }

    public function setDefault($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $m = $this->models();
        $model = $m->find((int) $id);
        if (! $model) return $this->failure('Model not found.', 404);

        // One default per model name across the cluster (builder, not Model::set()).
        $m->builder()->where('name', $model['name'])->update(['is_default' => 0]);
        $m->update($model['id'], ['is_default' => 1]);
        return $this->ok(null, 'Default model set.');
    }

    public function getConfig($id = null)
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        $model = $this->models()->find((int) $id);
        if (! $model) return $this->failure('Model not found.', 404);
        return $this->ok((new ModelConfigModel())->forModel($model['name']));
    }

    public function saveConfig($id = null)
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $model = $this->models()->find((int) $id);
        if (! $model) return $this->failure('Model not found.', 404);
        $saved = (new ModelConfigModel())->save_($model['name'], $this->input());
        return $this->ok($saved, 'Configuration saved.');
    }

    /** Resolve a model row id to [model, server, errorResponse|null]. */
    private function resolve($id): array
    {
        $model = $this->models()->find((int) $id);
        if (! $model) {
            return [null, null, $this->failure('Model not found.', 404)];
        }
        $server = $this->servers()->find($model['server_id']);
        if (! $server) {
            return [null, null, $this->failure('Owning server not found.', 404)];
        }
        return [$model, $server, null];
    }
}
