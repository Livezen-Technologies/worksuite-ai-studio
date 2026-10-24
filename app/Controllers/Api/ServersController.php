<?php

namespace App\Controllers\Api;

use App\Models\ServerModel;
use App\Models\ServerHealthModel;
use App\Services\ServerService;

/**
 * Server Manager API — register and operate Ollama nodes.
 *
 *   GET    /api/servers                 list
 *   POST   /api/servers                 create            (servers.manage)
 *   GET    /api/servers/{id}            detail + recent health
 *   PUT    /api/servers/{id}            update            (servers.manage)
 *   DELETE /api/servers/{id}            delete            (servers.manage)
 *   POST   /api/servers/{id}/health     run a health check (servers.manage)
 *   GET    /api/servers/{id}/models     installed models on the node
 *   POST   /api/servers/{id}/restart|shutdown|update  node control (needs agent)
 */
class ServersController extends BaseApiController
{
    private function servers(): ServerModel { return new ServerModel(); }

    public function index()
    {
        if ($r = $this->requirePermission('servers.view')) return $r;
        $m    = $this->servers();
        $rows = array_map([$m, 'publicRow'], $m->orderBy('id', 'ASC')->find());
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        if ($r = $this->requirePermission('servers.view')) return $r;
        $m   = $this->servers();
        $row = $m->find((int) $id);
        if (! $row) return $this->failure('Server not found.', 404);

        $data = $m->publicRow($row);
        $data['health_history'] = (new ServerHealthModel())->recent((int) $id, 50);
        return $this->ok($data);
    }

    public function create()
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        $in = $this->input();

        $payload  = $this->sanitize($in);
        $external = (new \App\Services\ProviderService())->isExternal($payload['kind'] ?? 'ollama');

        // Ollama nodes need an api_url; external providers default it + need a key.
        $rules = ['name' => 'required|min_length[2]|max_length[120]'];
        if ($external) {
            $rules['auth_token'] = 'required';
        } else {
            $rules['api_url'] = 'required|max_length[255]';
        }
        if ($errors = $this->validateFields($payload, $rules)) {
            return $this->failure($external ? 'A name and API key are required.' : 'Please check the server details.', 422, $errors);
        }

        $m  = $this->servers();
        $id = $m->insert($payload, true);
        if (! $id) {
            return $this->failure('Could not save the server.', 422, $m->errors());
        }

        // Best-effort initial probe so the row reflects reachability immediately.
        $server = $m->find($id);
        (new ServerService())->check($server);

        // Pull the provider's model catalogue so they appear in the picker now.
        if ($external) {
            try { (new \App\Services\ModelService())->syncServer($server); } catch (\Throwable $e) { /* surfaced via health */ }
        }

        return $this->ok($m->publicRow($m->find($id)), $external ? 'Provider connected.' : 'Server registered.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        $m   = $this->servers();
        $row = $m->find((int) $id);
        if (! $row) return $this->failure('Server not found.', 404);

        $payload = $this->sanitize($this->input(), true);
        if (! $m->update((int) $id, $payload)) {
            return $this->failure('Could not update the server.', 422, $m->errors());
        }
        return $this->ok($m->publicRow($m->find((int) $id)), 'Server updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        $m = $this->servers();
        if (! $m->find((int) $id)) return $this->failure('Server not found.', 404);
        $m->delete((int) $id);
        return $this->ok(null, 'Server removed.');
    }

    public function health($id = null)
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        $result = (new ServerService())->checkById((int) $id);
        if ($result === null) return $this->failure('Server not found.', 404);
        return $this->ok($result, 'Health check complete.');
    }

    public function models($id = null)
    {
        if ($r = $this->requirePermission('servers.view')) return $r;
        $server = $this->servers()->find((int) $id);
        if (! $server) return $this->failure('Server not found.', 404);

        $provider = new \App\Services\ProviderService();
        try {
            if ($provider->isExternal($server['kind'] ?? 'ollama')) {
                $names = $provider->listModels($server, true);
                return $this->ok(array_map(static fn ($n) => ['name' => $n], $names));
            }
            $tags = (new ServerService())->clientFor($server)->tags();
            return $this->ok($tags['models'] ?? []);
        } catch (\Throwable $e) {
            return $this->failure('Provider unreachable: ' . $e->getMessage(), 502);
        }
    }

    /**
     * Node lifecycle controls (restart / shutdown / update Ollama). These require
     * privileged access on the node itself (systemd) and are wired to a node
     * agent / SSH in deployment hardening (Phase 10). Returned as not-implemented
     * for now so the UI can surface a clear message.
     */
    public function control($id = null, $action = null)
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        if (! $this->servers()->find((int) $id)) return $this->failure('Server not found.', 404);
        return $this->failure(
            "Action '{$action}' requires the node control agent, configured during deployment (Phase 10).",
            501
        );
    }

    /** Whitelist + normalise incoming server fields. */
    private function sanitize(array $in, bool $partial = false): array
    {
        $fields = [
            'name', 'description', 'kind', 'hostname', 'ip_address', 'api_url', 'port', 'auth_token',
            'gpu_model', 'cuda_version', 'cpu_cores', 'ram_gb', 'storage_gb', 'os', 'is_local',
        ];
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $in)) {
                // On edit, a blank API key means "keep the existing key" — skip it.
                if ($partial && $f === 'auth_token' && $in[$f] === '') {
                    continue;
                }
                $out[$f] = $in[$f] === '' ? null : $in[$f];
            }
        }
        // Derive api_url from ip/port when omitted.
        if (! $partial && empty($out['api_url']) && ! empty($out['ip_address'])) {
            $port = (int) ($out['port'] ?? 11434);
            $out['api_url'] = "http://{$out['ip_address']}:{$port}";
        }
        if (isset($out['api_url']) && $out['api_url'] && ! preg_match('#^https?://#', $out['api_url'])) {
            $out['api_url'] = 'http://' . $out['api_url'];
        }
        return $out;
    }
}
