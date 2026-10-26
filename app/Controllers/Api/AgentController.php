<?php

namespace App\Controllers\Api;

use App\Services\TrainingPipelineService;

/**
 * Node training agent (public route; the node's agent token is the credential).
 *
 *   POST /api/agent/heartbeat   {version, job_id?, exited_job_id?, exit_code?, tail?}
 *        → {action: idle | run | continue | stop, …}   (?format=env answers KEY=VALUE lines for the shell agent)
 *
 * The token is issued on the Servers page ("Install training agent") and stored only as a hash.
 */
class AgentController extends BaseApiController
{
    public function heartbeat()
    {
        $auth = (string) $this->request->getHeaderLine('Authorization');
        $tok  = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $server = $tok === '' ? null : \Config\Database::connect()->table('servers')
            ->where('agent_token_hash', hash('sha256', $tok))->where('deleted_at', null)->get()->getRowArray();
        if (! $server) return $this->failure('Invalid agent token.', 401);

        $in = $this->request->getPost() ?: [];
        if (! $in) { try { $in = $this->input(); } catch (\Throwable) { $in = []; } }
        $out = (new TrainingPipelineService())->heartbeat($server, is_array($in) ? $in : []);

        if ($this->request->getGet('format') === 'env') {
            $lines = [];
            foreach ($out as $k => $v) $lines[] = strtoupper($k) . '=' . preg_replace('/[^\w.:\/+-]/', '', (string) $v);
            return $this->response->setContentType('text/plain')->setBody(implode("\n", $lines) . "\n");
        }
        return $this->ok($out);
    }
}
