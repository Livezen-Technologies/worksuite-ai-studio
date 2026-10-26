<?php

namespace App\Controllers\Api;

use App\Services\NodeEnrolmentService;

/**
 * Node enrolment API — connect a Mac, Linux or Windows machine as an Ollama node with the aistudio-node tool.
 *
 *   POST /api/servers/enrolments   issue a one-time token          (servers.manage)
 *   POST /api/nodes/enrol          token + public key → tunnel config (public; the token is the credential)
 *
 * `/api/nodes/enrol?format=env` answers KEY=VALUE lines so the shell tool needs no JSON parser.
 */
class NodesController extends BaseApiController
{
    public function issue()
    {
        if ($r = $this->requirePermission('servers.manage')) return $r;
        $in    = $this->input();
        $label = trim((string) ($in['label'] ?? '')) ?: null;
        $out   = (new NodeEnrolmentService())->issue($this->userId(), $label, (int) ($in['hours'] ?? 24));

        $base = rtrim((string) config('App')->baseURL, '/');
        $out['commands'] = [
            'unix'    => "curl -fsSL {$base}/node/aistudio-node.sh | bash -s -- --server {$base} --token {$out['token']}",
            'windows' => "& ([scriptblock]::Create((irm {$base}/node/aistudio-node.ps1))) -Server {$base} -Token {$out['token']}",
        ];
        return $this->ok($out, 'Enrolment token created.', 201);
    }

    public function enrol()
    {
        $in = $this->input();
        try {
            $out = (new NodeEnrolmentService())->enrol((string) ($in['token'] ?? ''), (string) ($in['public_key'] ?? ''), $in);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }

        if ($this->request->getGet('format') === 'env') {
            $lines = [];
            foreach ($out as $k => $v) {
                $lines[] = strtoupper($k) . '=' . str_replace(["\r", "\n"], ' ', (string) $v);
            }
            return $this->response->setStatusCode(201)->setContentType('text/plain')->setBody(implode("\n", $lines) . "\n");
        }
        return $this->ok($out, 'Node enrolled.', 201);
    }
}
