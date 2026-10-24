<?php

namespace App\Controllers\Api\Gateway;

use App\Controllers\Api\BaseApiController;
use App\Services\ApiGatewayService;
use App\Services\ProviderService;

/**
 * External API gateway (`/api/v1/*`), authenticated by ApiKeyFilter.
 *
 *   POST /api/v1/chat        { model, messages:[{role,content}], stream? }
 *   POST /api/v1/generate    { model, prompt }
 *   POST /api/v1/embeddings  { model, input }
 *   GET  /api/v1/models      models available through the gateway
 *
 * Routes traffic to an online node hosting the model (round-robin + failover),
 * enforces key scopes / allowed-models / token quota, and logs every call.
 */
class GatewayController extends BaseApiController
{
    private function key(): array { return $_SERVER['ohub_api_key'] ?? []; }

    public function models()
    {
        if ($r = $this->scope('models')) return $r;
        return $this->ok((new ApiGatewayService())->availableModels());
    }

    public function chat()
    {
        return $this->proxy('chat', function (array $server, string $model, array $in) {
            $messages = $in['messages'] ?? [];
            if (! is_array($messages) || ! $messages) {
                throw new \InvalidArgumentException('messages[] is required.');
            }
            $content = '';
            $final = (new ProviderService())->chat($server, $model, $messages, [], function (string $d) use (&$content) {
                $content .= $d;
            });
            return [
                'output' => ['role' => 'assistant', 'content' => $content],
                'usage'  => $final,
            ];
        });
    }

    public function generate()
    {
        return $this->proxy('generate', function (array $server, string $model, array $in) {
            $prompt = (string) ($in['prompt'] ?? '');
            if (trim($prompt) === '') {
                throw new \InvalidArgumentException('prompt is required.');
            }
            // Uniform across kinds: a one-shot prompt is a single-turn chat.
            $text  = '';
            $final = (new ProviderService())->chat($server, $model, [['role' => 'user', 'content' => $prompt]], [],
                function (string $d) use (&$text) { $text .= $d; });
            return ['output' => ['response' => $text], 'usage' => $final];
        });
    }

    /**
     * Sinhala ⇄ English translation. Body: { model, text, target?: 'si'|'en' }.
     * Without target the language is auto-detected and flipped. Uses the chat scope.
     */
    public function translate()
    {
        return $this->proxy('chat', function (array $server, string $model, array $in) {
            $text = trim((string) ($in['text'] ?? ''));
            if ($text === '') {
                throw new \InvalidArgumentException('text is required.');
            }
            $target = in_array($in['target'] ?? '', ['si', 'en'], true) ? $in['target'] : null;
            $rule   = $target === 'si'
                ? 'Translate the text to natural, contemporary Sinhala (සිංහල).'
                : ($target === 'en'
                    ? 'Translate the text to natural English.'
                    : 'Detect the language: Sinhala (or Singlish) → translate to English; English → translate to Sinhala.');
            $system = "You are a precise Sinhala ⇄ English translation engine. {$rule} "
                . 'Treat Singlish (Sinhala written in Latin letters) as Sinhala. '
                . 'Output ONLY the translation — no explanations, notes or quotes. '
                . 'Preserve tone, names, numbers and formatting.';
            $out   = '';
            $final = (new ProviderService())->chat($server, $model, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ], [], function (string $d) use (&$out) { $out .= $d; });
            return ['output' => ['translation' => trim($out)], 'usage' => $final];
        });
    }

    /**
     * Summarisation. Body: { model, text, language?: 'si'|'en', sentences?: int }.
     * Uses the chat scope.
     */
    public function summarize()
    {
        return $this->proxy('chat', function (array $server, string $model, array $in) {
            $text = trim((string) ($in['text'] ?? ''));
            if ($text === '') {
                throw new \InvalidArgumentException('text is required.');
            }
            $lang      = ($in['language'] ?? '') === 'en' ? 'English' : 'Sinhala (සිංහල)';
            $sentences = max(1, min(20, (int) ($in['sentences'] ?? 3)));
            $system    = "You are a summarisation engine. Summarise the user's text in {$lang}, "
                . "in at most {$sentences} sentences. Keep names, figures and key facts. "
                . 'Output ONLY the summary.';
            $out   = '';
            $final = (new ProviderService())->chat($server, $model, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ], [], function (string $d) use (&$out) { $out .= $d; });
            return ['output' => ['summary' => trim($out)], 'usage' => $final];
        });
    }

    public function embeddings()
    {
        return $this->proxy('embeddings', function (array $server, string $model, array $in) {
            $input = $in['input'] ?? ($in['prompt'] ?? null);
            if ($input === null || $input === '') {
                throw new \InvalidArgumentException('input is required.');
            }
            $res = (new ProviderService())->embeddings($server, $model, $input);
            return ['output' => ['embeddings' => $res['embeddings'] ?? []], 'usage' => []];
        });
    }

    /**
     * Shared proxy pipeline: scope/model/quota checks → node selection with
     * failover → run $call → token-accounting + logging.
     */
    private function proxy(string $endpoint, callable $call)
    {
        if ($r = $this->scope($endpoint)) return $r;
        $key   = $this->key();
        $in    = $this->input();
        $model = trim((string) ($in['model'] ?? ''));
        if ($model === '') {
            return $this->logged($endpoint, $model, null, 422, 'model is required.');
        }
        if ($err = $this->modelAllowed($model)) return $err;

        $gw = new ApiGatewayService();
        if ($quota = $gw->quotaError($key)) {
            return $this->logged($endpoint, $model, null, 429, $quota);
        }

        $candidates = $gw->candidates($model);
        if (! $candidates) {
            return $this->logged($endpoint, $model, null, 503, 'No online node hosts this model.');
        }

        $start    = microtime(true);
        $lastErr  = 'All nodes failed.';
        foreach ($candidates as $server) {
            try {
                $result = $call($server, $model, $in);
                $usage  = $result['usage'] ?? [];
                $pt = $usage['prompt_tokens'] ?? null;
                $ct = $usage['completion_tokens'] ?? null;
                $gw->log([
                    'api_key_id' => $key['id'], 'user_id' => $key['user_id'] ?? null,
                    'endpoint' => $endpoint, 'model' => $model, 'server_id' => $server['id'],
                    'prompt_tokens' => $pt, 'completion_tokens' => $ct,
                    'total_tokens' => ($pt ?? 0) + ($ct ?? 0),
                    'processing_ms' => (int) round((microtime(true) - $start) * 1000),
                    'status' => 200, 'client_ip' => $this->request->getIPAddress(),
                ]);
                return $this->ok([
                    'model'  => $model,
                    'server' => $server['name'],
                    'result' => $result['output'],
                    'usage'  => ['prompt_tokens' => $pt, 'completion_tokens' => $ct, 'total_tokens' => ($pt ?? 0) + ($ct ?? 0)],
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->logged($endpoint, $model, $server['id'], 422, $e->getMessage());
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage(); // try the next node (failover)
            }
        }
        return $this->logged($endpoint, $model, null, 502, $lastErr);
    }

    /** Enforce that the key carries the scope for this endpoint. */
    private function scope(string $scope)
    {
        $scopes = explode(',', (string) ($this->key()['scopes'] ?? ''));
        if (! in_array($scope, $scopes, true)) {
            return $this->failure("This key lacks the '{$scope}' scope.", 403);
        }
        return null;
    }

    /** Enforce the key's allowed-models list, if set. */
    private function modelAllowed(string $model)
    {
        $allowed = ($this->key()['allowed_models'] ?? null);
        $allowed = $allowed ? json_decode($allowed, true) : null;
        if (is_array($allowed) && $allowed && ! in_array($model, $allowed, true)) {
            return $this->failure("Model '{$model}' is not allowed for this key.", 403);
        }
        return null;
    }

    /** Log an error outcome and return the matching failure envelope. */
    private function logged(string $endpoint, string $model, ?int $serverId, int $status, string $message)
    {
        $key = $this->key();
        (new ApiGatewayService())->log([
            'api_key_id' => $key['id'] ?? null, 'user_id' => $key['user_id'] ?? null,
            'endpoint' => $endpoint, 'model' => $model ?: null, 'server_id' => $serverId,
            'status' => $status, 'client_ip' => $this->request->getIPAddress(),
            'error' => substr($message, 0, 255),
        ]);
        return $this->failure($message, $status);
    }
}
