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

    /** Fleet totals for the stat cards on the Servers page. */
    public function summary()
    {
        if ($r = $this->requirePermission('servers.view')) return $r;
        $rows  = $this->servers()->where('kind', 'ollama')->findAll();
        $vram  = 0.0;
        $gpus  = 0;
        $cuda  = 0;
        $known = 0;
        foreach ($rows as $s) {
            if (! empty($s['gpu_model'])) {
                $gpus++;
                if ($c = self::cudaCores($s['gpu_model'])) {
                    $cuda += $c;
                    $known++;
                }
                // Enrolled nodes record "Name, 4096 MiB".
                if (preg_match('/([\d.]+)\s*MiB/i', $s['gpu_model'], $m)) {
                    $vram += (float) $m[1] / 1024;
                }
            }
        }
        $since = date('Y-m-d H:i:s', time() - 86400);
        $use   = \Config\Database::connect()->table('api_logs')
            ->select('COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens')
            ->where('created_at >=', $since)->where('server_id IS NOT NULL')->get()->getRowArray();

        return $this->ok([
            'servers'      => count($rows),
            'online'       => count(array_filter($rows, static fn ($s) => $s['status'] === 'online')),
            'cpu_cores'    => array_sum(array_map(static fn ($s) => (int) $s['cpu_cores'], $rows)),
            'ram_gb'       => round(array_sum(array_map(static fn ($s) => (float) $s['ram_gb'], $rows)), 1),
            'gpus'         => $gpus,
            'vram_gb'      => round($vram, 1),
            'cuda_cores'   => $cuda,
            'cuda_known'   => $known,
            'requests_24h' => (int) $use['requests'],
            'tokens_24h'   => (int) $use['tokens'],
        ]);
    }

    /** CUDA cores for common NVIDIA GPUs (nvidia-smi doesn't report them). Longest matching name wins. */
    private static function cudaCores(string $gpu): ?int
    {
        static $table = [
            'H100' => 14592, 'H200' => 16896, 'A100' => 6912, 'A40' => 10752, 'A30' => 3584, 'A10' => 9216, 'L40S' => 18176, 'L40' => 18176,
            'L4' => 7424, 'T4' => 2560, 'V100' => 5120, 'P100' => 3584,
            'RTX 6000 Ada' => 18176, 'RTX A6000' => 10752, 'RTX A5000' => 8192, 'RTX A4500' => 7168, 'RTX A4000' => 6144, 'RTX A2000' => 3328,
            'Quadro RTX 8000' => 4608, 'Quadro RTX 6000' => 4608, 'Quadro RTX 5000' => 3072, 'Quadro RTX 4000' => 2304,
            'T1000' => 896, 'T600' => 640, 'T400' => 384, 'T2000' => 1024, 'T1200' => 1024, 'P2000' => 1024, 'P1000' => 640,
            'RTX 5090' => 21760, 'RTX 5080' => 10752, 'RTX 5070 Ti' => 8960, 'RTX 5070' => 6144, 'RTX 5060 Ti' => 4608,
            'RTX 4090' => 16384, 'RTX 4080 SUPER' => 10240, 'RTX 4080' => 9728, 'RTX 4070 Ti SUPER' => 8448, 'RTX 4070 Ti' => 7680,
            'RTX 4070 SUPER' => 7168, 'RTX 4070' => 5888, 'RTX 4060 Ti' => 4352, 'RTX 4060' => 3072,
            'RTX 3090 Ti' => 10752, 'RTX 3090' => 10496, 'RTX 3080 Ti' => 10240, 'RTX 3080' => 8704, 'RTX 3070 Ti' => 6144, 'RTX 3070' => 5888,
            'RTX 3060 Ti' => 4864, 'RTX 3060' => 3584, 'RTX 3050' => 2560,
            'RTX 2080 Ti' => 4352, 'RTX 2080 SUPER' => 3072, 'RTX 2080' => 2944, 'RTX 2070 SUPER' => 2560, 'RTX 2070' => 2304, 'RTX 2060 SUPER' => 2176, 'RTX 2060' => 1920,
            'GTX 1660 Ti' => 1536, 'GTX 1660 SUPER' => 1408, 'GTX 1660' => 1408, 'GTX 1650' => 896, 'GTX 1080 Ti' => 3584, 'GTX 1080' => 2560, 'GTX 1070' => 1920, 'GTX 1060' => 1280,
        ];
        $best = null;
        foreach ($table as $name => $cores) {
            if (stripos($gpu, $name) !== false && ($best === null || strlen($name) > strlen($best))) {
                $best = $name;
            }
        }
        return $best ? $table[$best] : null;
    }

    /**
     * Usage, analytics and logs for one server: gateway calls it served (api_logs) and its health checks.
     *   GET /api/servers/{id}/usage?days=7
     */
    public function usage($id = null)
    {
        if ($r = $this->requirePermission('servers.view')) return $r;
        if (! $this->servers()->find((int) $id)) return $this->failure('Server not found.', 404);
        $days  = max(1, min(90, (int) ($this->request->getGet('days') ?? 7)));
        $since = date('Y-m-d H:i:s', strtotime('-' . ($days - 1) . ' days midnight'));
        $db    = \Config\Database::connect();
        $logs  = static fn () => $db->table('api_logs')->where('server_id', (int) $id)->where('created_at >=', $since);

        $totals = $logs()->select('COUNT(*) AS requests, COALESCE(SUM(status >= 400), 0) AS errors, COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
            COALESCE(SUM(completion_tokens), 0) AS completion_tokens, COALESCE(SUM(total_tokens), 0) AS tokens, COALESCE(ROUND(AVG(processing_ms)), 0) AS avg_ms', false)
            ->get()->getRowArray();
        $daily = $logs()->select('DATE(created_at) AS day, COUNT(*) AS requests, SUM(status >= 400) AS errors, COALESCE(SUM(total_tokens), 0) AS tokens', false)
            ->groupBy('DATE(created_at)')->orderBy('day', 'ASC')->get()->getResultArray();
        $byModel = $logs()->select("COALESCE(model, '—') AS model, COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens, ROUND(AVG(processing_ms)) AS avg_ms, SUM(status >= 400) AS errors", false)
            ->groupBy('model')->orderBy('requests', 'DESC')->limit(20)->get()->getResultArray();
        $recent = $db->table('api_logs')->select('created_at, endpoint, model, status, total_tokens, processing_ms, error')
            ->where('server_id', (int) $id)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $checks = $db->table('server_health')->select('checked_at, status, health_score, latency_ms, models_total, models_running, error')
            ->where('server_id', (int) $id)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $uptime = $db->table('server_health')->select("COUNT(*) AS checks, SUM(status IN ('online','degraded')) AS up", false)
            ->where('server_id', (int) $id)->where('checked_at >=', $since)->get()->getRowArray();

        $totals = array_map('intval', $totals);
        $totals['uptime_pct'] = $uptime['checks'] ? round($uptime['up'] / $uptime['checks'] * 100, 1) : null;

        // One bar per day, including days without traffic.
        $byDay  = array_column($daily, null, 'day');
        $series = [];
        for ($t = strtotime($since); $t <= time(); $t += 86400) {
            $d        = date('Y-m-d', $t);
            $series[] = ['day' => $d, 'requests' => (int) ($byDay[$d]['requests'] ?? 0), 'errors' => (int) ($byDay[$d]['errors'] ?? 0), 'tokens' => (int) ($byDay[$d]['tokens'] ?? 0)];
        }

        return $this->ok(['days' => $days, 'totals' => $totals, 'daily' => $series, 'by_model' => $byModel, 'recent' => $recent, 'checks' => $checks]);
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
