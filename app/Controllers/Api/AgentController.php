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
    /** Speech models a GPU node runs (catalog key => folder under the models root) and the service code it needs. */
    public const ASR_MODELS = [
        'sinhala'         => 'whisper-small-sinhala-ct2-int8',
        'sinhala_numbers' => 'whisper-small-sinhala-numbers-ct2-int8',
        'base'            => 'faster-whisper-base',    // language of each phrase in mixed speech
        'small'           => 'faster-whisper-small',   // English phrases in mixed speech
    ];
    private const ASR_ROOT = '/srv/sinhala-data/models/';

    /** Model folders a node may fetch: the built-ins plus custom models in the speech catalog (folder name → path). */
    private function asrDirs(): array
    {
        $dirs = [];
        foreach (self::ASR_MODELS as $d) $dirs[$d] = self::ASR_ROOT . $d;
        foreach (\App\Services\SpeechModelService::catalog() as $m) {
            if (! empty($m['dir']) && str_starts_with($m['dir'], \App\Services\SpeechModelService::ROOT . '/')) $dirs[basename($m['dir'])] = $m['dir'];
        }
        return $dirs;
    }

    private function agentServer(): ?array
    {
        $auth = (string) $this->request->getHeaderLine('Authorization');
        $tok  = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        return $tok === '' ? null : \Config\Database::connect()->table('servers')
            ->where('agent_token_hash', hash('sha256', $tok))->where('deleted_at', null)->get()->getRowArray();
    }

    /** GET /api/agent/asr/manifest — lines "path size sha256-or-dash" for the speech service and its models. */
    public function asrManifest()
    {
        if (! $this->agentServer()) return $this->failure('Invalid agent token.', 401);
        $lines = [];
        foreach (['asr_service.py', 'numfix.py'] as $f) {
            $p = ROOTPATH . 'deploy/asr/' . $f;
            $lines[] = "code/{$f} " . filesize($p) . ' ' . hash_file('sha256', $p);
        }
        if (is_file(\App\Services\SpeechModelService::CATALOG)) {
            $c = \App\Services\SpeechModelService::CATALOG;
            $lines[] = 'code/catalog.json ' . filesize($c) . ' ' . hash_file('sha256', $c);
        }
        foreach ($this->asrDirs() as $dir => $path) {
            foreach (glob($path . '/*') ?: [] as $p) {
                if (! is_file($p) || str_ends_with($p, '.md')) continue;
                $lines[] = "models/{$dir}/" . basename($p) . ' ' . filesize($p) . ' -';   // size identifies a model file version
            }
        }
        return $this->response->setContentType('text/plain')->setBody(implode("\n", $lines) . "\n");
    }

    /** GET /api/agent/asr/file?path=code/asr_service.py | models/<dir>/<file> — only manifest entries. */
    public function asrFile()
    {
        if (! $this->agentServer()) return $this->failure('Invalid agent token.', 401);
        $path = (string) ($this->request->getGet('path') ?? '');
        $dirs = $this->asrDirs();
        if (preg_match('#^code/(asr_service\.py|numfix\.py)$#', $path, $m)) {
            $file = ROOTPATH . 'deploy/asr/' . $m[1];
        } elseif ($path === 'code/catalog.json') {
            $file = \App\Services\SpeechModelService::CATALOG;
        } elseif (preg_match('#^models/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)$#', $path, $m) && isset($dirs[$m[1]])) {
            $file = $dirs[$m[1]] . '/' . $m[2];
        } else {
            return $this->failure('Not a speech file.', 404);
        }
        if (! is_file($file)) return $this->failure('Not found.', 404);
        @set_time_limit(0);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function heartbeat()
    {
        $server = $this->agentServer();
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
