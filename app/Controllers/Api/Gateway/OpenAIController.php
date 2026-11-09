<?php

namespace App\Controllers\Api\Gateway;

use App\Controllers\Api\BaseApiController;
use App\Services\ApiGatewayService;
use App\Services\ModelRegistryService;
use App\Services\ProviderService;

/**
 * Unified, OpenAI-compatible AI API gateway — base URL `/api/openai/v1`.
 *
 * Any OpenAI SDK or tool works by pointing its base URL here and using an AI Studio credential as
 * the API key: `ohub_…:sk_…`, or a short-lived access token from /api/v1/oauth/token.
 *
 *   GET  /models                 models this key may use (OpenAI list format, with type and capabilities)
 *   GET  /models/{id}            one model
 *   POST /chat/completions       chat; `stream: true` for server-sent events; text + image (data: URL) content;
 *                                tools / tool_choice (function calling) on every engine: OpenAI-compatible, Ollama,
 *                                Anthropic and Gemini — tool calls come back in the OpenAI format
 *   POST /completions            legacy text completion (also streams)
 *   POST /embeddings             embeddings; float or base64 encoding
 *   POST /audio/transcriptions   speech to text (multipart `file`); json, text, verbose_json, srt or vtt; word timings
 *
 * Every request goes through the same pipeline as the native gateway: key scopes, the key's model
 * allowlist, model access roles from the registry, token quotas, node selection with failover, and
 * request logging. A request with no model (or "default") uses the registry's default for the task.
 * Errors use the OpenAI error shape.
 */
class OpenAIController extends BaseApiController
{
    private string $requestId = '';
    private float $start = 0.0;

    private function key(): array { return $_SERVER['ohub_api_key'] ?? []; }

    // ---- Models ------------------------------------------------------------------------

    public function models()
    {
        if ($e = $this->scopeError('models')) return $e;
        return $this->json(['object' => 'list', 'data' => array_values($this->visibleModels())]);
    }

    public function model(...$parts)
    {
        if ($e = $this->scopeError('models')) return $e;
        $id = urldecode(implode('/', $parts));
        $all = $this->visibleModels();
        return isset($all[$id]) ? $this->json($all[$id]) : $this->error("The model '{$id}' does not exist or you do not have access to it.", 404, 'model_not_found');
    }

    /** id => OpenAI model object, for models this key may call. */
    private function visibleModels(): array
    {
        $key      = $this->key();
        $allowed  = ! empty($key['allowed_models']) ? json_decode($key['allowed_models'], true) : null;
        $registry = new ModelRegistryService();
        $role     = $registry->roleOf(isset($key['user_id']) ? (int) $key['user_id'] : null);
        $meta     = $registry->index(); // one query; roles are checked in memory
        $out = [];
        foreach ((new ApiGatewayService())->availableModels(true) as $m) {
            $id = $m['name'];
            if (is_array($allowed) && $allowed && ! in_array($id, $allowed, true)) continue;
            $r = $meta[$id] ?? null;
            if (! ModelRegistryService::rowAllows($r, $role)) continue;
            $guess = $r ? null : ModelRegistryService::infer($id);
            $out[$id] = [
                'id' => $id, 'object' => 'model', 'created' => isset($r['created_at']) ? (int) strtotime($r['created_at']) : 0, 'owned_by' => 'worksuite-ai',
                'type' => $r['type'] ?? $guess['type'],
                'capabilities' => $r ? (json_decode((string) $r['capabilities'], true) ?: []) : $guess['capabilities'],
                'context_length' => isset($r['context_length']) ? (int) $r['context_length'] : null,
            ];
        }
        // Speech models (the speech service), for keys with the transcribe scope.
        if (in_array('transcribe', explode(',', (string) ($key['scopes'] ?? '')), true)) {
            foreach ((new \App\Services\SpeechService())->models() as $k => $m) {
                $id = \App\Services\SpeechService::registryName($k);
                if ($m['installed'] === false || (is_array($allowed) && $allowed && ! in_array($id, $allowed, true)) || ! ModelRegistryService::rowAllows($meta[$id] ?? null, $role)) continue;
                $out[$id] = ['id' => $id, 'object' => 'model', 'created' => 0, 'owned_by' => 'worksuite-ai', 'type' => 'stt', 'capabilities' => ['audio_in'], 'context_length' => null];
            }
        }
        return $out;
    }

    // ---- Speech to text ------------------------------------------------------------------------

    /**
     * OpenAI `POST /audio/transcriptions`. Multipart: file (≤ 500 MB audio or video), model (whisper:<key>,
     * a bare key, "whisper-1" or "default" → the registry's transcription default), language (ISO, e.g. si,
     * en; "mix" for Sinhala + English), response_format, timestamp_granularities[] (word, segment).
     */
    public function transcriptions()
    {
        $this->begin();
        if ($e = $this->scopeError('transcribe')) return $e;
        $file = $this->request->getFile('file');
        // is_uploaded_file() is always false under the CLI test runner; only there a plain temp file counts.
        $ok = $file && ($file->isValid() || (ENVIRONMENT === 'testing' && $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName())));
        if (! $ok) return $this->error('file is required (multipart/form-data).', 400, 'missing_required_parameter', 'file');
        if ($file->getSize() > 500 * 1024 * 1024) return $this->error('The file can be up to 500 MB.', 413, 'file_too_large', 'file');

        $key   = $this->key();
        $reg   = new ModelRegistryService();
        $asked = trim((string) ($this->request->getPost('model') ?? ''));
        if ($asked === '' || in_array($asked, ['default', 'whisper-1'], true)) $asked = (string) ($reg->defaultFor('transcription', $key['application'] ?? null) ?? 'whisper:sinhala');
        $speechKey = \App\Services\SpeechService::keyFrom($asked);
        if ($speechKey === null) return $this->error("Unknown speech model '{$asked}'.", 400, 'invalid_value', 'model');
        $model = \App\Services\SpeechService::registryName($speechKey);
        $allowed = ! empty($key['allowed_models']) ? json_decode($key['allowed_models'], true) : null;
        if (is_array($allowed) && $allowed && ! in_array($model, $allowed, true)) {
            return $this->failLogged('audio.transcriptions', $model, null, 403, "This API key may not use the model '{$model}'.", 'model_not_allowed');
        }
        if (! $reg->roleAllowed($model, $reg->roleOf(isset($key['user_id']) ? (int) $key['user_id'] : null))) {
            return $this->failLogged('audio.transcriptions', $model, null, 403, "The model '{$model}' is not available to this key's owner.", 'model_not_allowed');
        }

        if ($q = (new ApiGatewayService())->quotaError($key)) {
            return $this->failLogged('audio.transcriptions', $model, null, 429, $q, 'insufficient_quota');
        }
        $lang = strtolower(trim((string) ($this->request->getPost('language') ?? '')));
        $lang = match (true) {
            $lang === '' => 'auto', in_array($lang, ['si', 'en', 'mix'], true) => $lang, str_starts_with($lang, 'si') => 'si', str_starts_with($lang, 'en') => 'en', default => 'auto',
        };
        $format = (string) ($this->request->getPost('response_format') ?? 'json');
        if (! in_array($format, ['json', 'text', 'verbose_json', 'srt', 'vtt'], true)) return $this->error('response_format must be json, text, verbose_json, srt or vtt.', 400, 'invalid_value', 'response_format');
        $gran  = (array) ($this->request->getPost('timestamp_granularities') ?? $this->request->getPost('timestamp_granularities[]') ?? []);
        $words = in_array('word', $gran, true);

        $svc = new \App\Services\SpeechService();
        [$code, $j, $used, $err] = $svc->transcribe($file->getTempName(), ['language' => $lang, 'model' => $speechKey, 'words' => $words], $svc->targets('auto'));
        if ($j === null || $code !== 200) {
            $msg = $code === 0 ? 'The speech service is not running.' : ($j['error'] ?? 'Transcription failed.');
            return $this->failLogged('audio.transcriptions', $model, $used['server_id'] ?? null, $code >= 400 && $code < 500 ? $code : ($code === 0 ? 503 : 502), $msg, 'upstream_error');
        }
        $this->logCall('audio.transcriptions', $model, $used['server_id'] ?? null, 200, null, null);
        $text = (string) ($j['text'] ?? '');
        $segs = array_values((array) ($j['segments'] ?? []));
        $stamp = static function (float $t, string $sep): string {
            $ms = (int) round($t * 1000);
            return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $sep, $ms % 1000);
        };
        switch ($format) {
            case 'text':
                return $this->plain($text . "\n", 'text/plain');
            case 'srt':
            case 'vtt':
                $sep = $format === 'srt' ? ',' : '.';
                $out = $format === 'vtt' ? "WEBVTT\n\n" : '';
                foreach ($segs as $i => $sg) {
                    $out .= ($format === 'srt' ? ($i + 1) . "\n" : '') . $stamp((float) $sg['start'], $sep) . ' --> ' . $stamp((float) $sg['end'], $sep) . "\n" . trim((string) $sg['text']) . "\n\n";
                }
                return $this->plain($out, $format === 'srt' ? 'application/x-subrip' : 'text/vtt');
            case 'verbose_json':
                $body = ['task' => 'transcribe', 'language' => $j['language'] ?? $lang, 'duration' => (float) ($j['duration'] ?? 0), 'text' => $text,
                    'segments' => array_map(static fn ($sg, $i) => ['id' => $i, 'start' => (float) $sg['start'], 'end' => (float) $sg['end'], 'text' => (string) $sg['text']], $segs, array_keys($segs))];
                if ($words) {
                    $body['words'] = [];
                    foreach ($segs as $sg) foreach ((array) ($sg['words'] ?? []) as $w) $body['words'][] = ['word' => (string) ($w['word'] ?? $w['text'] ?? ''), 'start' => (float) ($w['start'] ?? 0), 'end' => (float) ($w['end'] ?? 0)];
                }
                return $this->json($body);
            default:
                return $this->json(['text' => $text]);
        }
    }

    private function plain(string $body, string $type)
    {
        return $this->response->setStatusCode(200)->setContentType($type)->setBody($body)->setHeader('x-request-id', $this->requestId);
    }

    // ---- Chat ------------------------------------------------------------------------------

    public function chatCompletions()
    {
        $in = $this->body();
        if ($in === null) return $this->error('The request body must be JSON.', 400);
        if (! empty($in['functions']) || isset($in['function_call'])) {
            return $this->error('The legacy functions / function_call parameters are not supported; use tools and tool_choice.', 400, 'unsupported_parameter', 'functions');
        }
        try {
            $tools = ProviderService::normalizeTools(is_array($in['tools'] ?? null) ? $in['tools'] : []);
            ProviderService::normalizeToolChoice($in['tool_choice'] ?? null, $tools);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, 'invalid_value', isset($in['tool_choice']) && ! str_starts_with($e->getMessage(), 'tools') ? 'tool_choice' : 'tools');
        }
        if ((int) ($in['n'] ?? 1) > 1) return $this->error('Only n=1 is supported.', 400, 'unsupported_parameter', 'n');
        try {
            $messages = $this->messages($in['messages'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, 'invalid_value', 'messages');
        }
        return $this->run('chat', 'chat.completions', $in, $messages, (bool) ($in['stream'] ?? false), 'chat.completion',
            $in['tools'] ?? [], $in['tool_choice'] ?? null);
    }

    public function completions()
    {
        $in = $this->body();
        if ($in === null) return $this->error('The request body must be JSON.', 400);
        $prompt = $in['prompt'] ?? null;
        if (is_array($prompt)) {
            if (count($prompt) > 1) return $this->error('Only a single prompt per request is supported.', 400, 'unsupported_parameter', 'prompt');
            $prompt = $prompt[0] ?? '';
        }
        if (! is_string($prompt) || trim($prompt) === '') return $this->error('prompt is required.', 400, 'missing_required_parameter', 'prompt');
        return $this->run('generate', 'completions', $in, [['role' => 'user', 'content' => $prompt]], (bool) ($in['stream'] ?? false), 'text_completion');
    }

    /**
     * Shared chat / completion pipeline. $object is "chat.completion" or "text_completion".
     */
    private function run(string $scope, string $endpoint, array $in, array $messages, bool $stream, string $object, array $tools = [], $toolChoice = null)
    {
        $this->begin();
        [$model, $err] = $this->resolveModel($scope, $endpoint, $in);
        if ($err) return $err;
        $gw = new ApiGatewayService();
        $candidates = $gw->candidates($model);
        if (! $candidates) return $this->failLogged($endpoint, $model, null, 503, 'No online node is serving this model right now.', 'model_unavailable');

        $params = ProviderService::cleanParams([
            'temperature' => $in['temperature'] ?? null, 'top_p' => $in['top_p'] ?? null,
            'max_tokens' => $this->capTokens($in['max_completion_tokens'] ?? ($in['max_tokens'] ?? null)), 'stop' => $in['stop'] ?? null,
            'seed' => $in['seed'] ?? null, 'presence_penalty' => $in['presence_penalty'] ?? null, 'frequency_penalty' => $in['frequency_penalty'] ?? null,
            'json' => in_array($in['response_format']['type'] ?? '', ['json_object', 'json_schema'], true),
        ]);
        $id      = ($object === 'chat.completion' ? 'chatcmpl-' : 'cmpl-') . bin2hex(random_bytes(12));
        $created = time();
        $chunkObj = $object === 'chat.completion' ? 'chat.completion.chunk' : 'text_completion';
        $includeUsage = (bool) ($in['stream_options']['include_usage'] ?? false);

        $started = false;
        $text    = '';
        $chunk = function (array $choice, ?array $usage = null) use ($id, $created, $model, $chunkObj) {
            $c = ['id' => $id, 'object' => $chunkObj, 'created' => $created, 'model' => $model, 'choices' => $choice ? [$choice] : []];
            if ($usage !== null) $c['usage'] = $usage;
            echo 'data: ' . json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        };
        $onDelta = function (string $d) use (&$started, &$text, $stream, $chunk, $object) {
            $text .= $d;
            if (! $stream) return;
            if (! $started) { // headers go out with the first token, so a node that fails before answering can still fail over
                $started = true;
                $this->openStream();
                if ($object === 'chat.completion') $chunk(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]);
            }
            $chunk($object === 'chat.completion'
                ? ['index' => 0, 'delta' => ['content' => $d], 'finish_reason' => null]
                : ['index' => 0, 'text' => $d, 'finish_reason' => null]);
        };

        $lastErr = 'Every node serving this model failed.';
        foreach ($candidates as $server) {
            try {
                $final = (new ProviderService())->chat($server, $model, $messages, ['params' => $params, 'tools' => $tools, 'tool_choice' => $toolChoice], $onDelta);
            } catch (\Throwable $e) {
                $lastErr = $this->publicError($e, 'The model node failed.');
                if ($started) { // mid-stream: cannot switch nodes any more; end the stream with an error event
                    // what was already streamed was generated, and counts
                    $this->logCall($endpoint, $model, (int) $server['id'], 502, ApiGatewayService::estimateTokens($messages), ApiGatewayService::estimateTokens($text), $lastErr);
                    echo 'data: ' . json_encode(['error' => ['message' => $lastErr, 'type' => 'api_error', 'code' => 'upstream_error']]) . "\n\ndata: [DONE]\n\n";
                    flush();
                    exit;
                }
                $text = '';
                $this->logCall($endpoint, $model, (int) $server['id'], 502, null, null, 'failover: ' . $lastErr); // per-node error rates stay real
                continue; // fail over to the next node
            }
            // Providers that report no usage still count toward quotas, by estimate.
            $pt = $final['prompt_tokens'] ?? ApiGatewayService::estimateTokens($messages);
            $ct = $final['completion_tokens'] ?? ApiGatewayService::estimateTokens($text);
            $usage  = ['prompt_tokens' => (int) $pt, 'completion_tokens' => (int) $ct, 'total_tokens' => (int) $pt + (int) $ct];
            $calls  = array_map(static fn ($tc, $i) => ['index' => $i, 'id' => $tc['id'], 'type' => 'function', 'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']]],
                $final['tool_calls'] ?? [], array_keys($final['tool_calls'] ?? []));
            // The provider's own reason (normalised by ProviderService) wins; otherwise infer from max_tokens.
            $finish = $calls ? 'tool_calls' : ($final['finish_reason'] ?? ((isset($params['max_tokens']) && $ct >= $params['max_tokens']) ? 'length' : 'stop'));
            $this->logCall($endpoint, $model, (int) $server['id'], 200, $pt, $ct);

            if ($stream) {
                if (! $started) { $this->openStream(); if ($object === 'chat.completion') $chunk(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $calls ? null : ''], 'finish_reason' => null]); }
                foreach ($calls as $c) $chunk(['index' => 0, 'delta' => ['tool_calls' => [$c]], 'finish_reason' => null]); // each call whole, in one delta
                $chunk($object === 'chat.completion' ? ['index' => 0, 'delta' => (object) [], 'finish_reason' => $finish] : ['index' => 0, 'text' => '', 'finish_reason' => $finish]);
                if ($includeUsage) $chunk([], $usage);
                echo "data: [DONE]\n\n";
                flush();
                exit;
            }
            $choice = $object === 'chat.completion'
                ? ['index' => 0, 'message' => ['role' => 'assistant', 'content' => ($calls && $text === '') ? null : $text]
                    + ($calls ? ['tool_calls' => array_map(static function ($c) { unset($c['index']); return $c; }, $calls)] : []), 'logprobs' => null, 'finish_reason' => $finish]
                : ['index' => 0, 'text' => $text, 'logprobs' => null, 'finish_reason' => $finish];
            return $this->json(['id' => $id, 'object' => $object, 'created' => $created, 'model' => $model,
                'system_fingerprint' => 'worksuite-' . substr(md5((string) $server['id']), 0, 8), 'choices' => [$choice], 'usage' => $usage]);
        }
        return $this->failLogged($endpoint, $model, null, 502, $lastErr, 'upstream_error');
    }

    // ---- Embeddings ------------------------------------------------------------------------

    public function embeddings()
    {
        $this->begin();
        $in = $this->body();
        if ($in === null) return $this->error('The request body must be JSON.', 400);
        $input = $in['input'] ?? null;
        $list  = is_array($input) ? array_values($input) : [$input];
        // Token-array input (what OpenAI-oriented clients such as LangChain send by default) is not
        // portable across engines' tokenizers: say so plainly instead of a vague validation error.
        if ($list && (is_int($list[0]) || is_array($list[0]))) {
            return $this->error('Token-array input is not supported; send the text. (In LangChain set check_embedding_ctx_length=False.)', 400, 'unsupported_value', 'input');
        }
        if (! $list || array_filter($list, static fn ($t) => ! is_string($t) || $t === '')) {
            return $this->error('input must be a non-empty string or an array of strings.', 400, 'invalid_value', 'input');
        }
        if (count($list) > 2048) return $this->error('At most 2048 inputs per request.', 400, 'invalid_value', 'input');
        [$model, $err] = $this->resolveModel('embeddings', 'embeddings', $in);
        if ($err) return $err;
        $candidates = (new ApiGatewayService())->candidates($model);
        if (! $candidates) return $this->failLogged('embeddings', $model, null, 503, 'No online node is serving this model right now.', 'model_unavailable');
        $base64  = ($in['encoding_format'] ?? 'float') === 'base64';
        $lastErr = 'Every node serving this model failed.';
        foreach ($candidates as $server) {
            try {
                $res = (new ProviderService())->embeddings($server, $model, $list);
            } catch (\Throwable $e) {
                $lastErr = $this->publicError($e, 'The model node failed.');
                continue;
            }
            $vecs = $res['embeddings'] ?? [];
            if (count($vecs) !== count($list)) { $lastErr = 'The node returned ' . count($vecs) . ' embeddings for ' . count($list) . ' inputs.'; continue; }
            // `dimensions`: shorten each vector and re-normalise it, as OpenAI does for its v3 models. Useful for
            // models trained for it (nomic-embed v1.5, mxbai, bge-m3 Matryoshka); a plain cut otherwise.
            $dims = (int) ($in['dimensions'] ?? 0);
            if ($dims > 0) {
                $vecs = array_map(static function (array $v) use ($dims) {
                    $v = array_slice($v, 0, $dims);
                    $norm = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $v))) ?: 1.0;
                    return array_map(static fn ($x) => $x / $norm, $v);
                }, $vecs);
            }
            $pt   = (int) ($res['prompt_eval_count'] ?? array_sum(array_map(static fn ($t) => (int) ceil(mb_strlen($t) / 4), $list)));
            $this->logCall('embeddings', $model, (int) $server['id'], 200, $pt, 0);
            $data = [];
            foreach ($vecs as $i => $v) {
                $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $base64 ? base64_encode(pack('g*', ...array_map('floatval', $v))) : $v];
            }
            return $this->json(['object' => 'list', 'data' => $data, 'model' => $model, 'usage' => ['prompt_tokens' => $pt, 'total_tokens' => $pt]]);
        }
        return $this->failLogged('embeddings', $model, null, 502, $lastErr, 'upstream_error');
    }

    // ---- Pipeline helpers --------------------------------------------------------------------

    /** The key's max_output_tokens ceiling applies whatever the request asks for. */
    private function capTokens($asked): ?int
    {
        $cap = (int) ($this->key()['max_output_tokens'] ?? 0);
        $asked = is_numeric($asked) && (int) $asked > 0 ? (int) $asked : null;
        return $cap > 0 ? min($cap, $asked ?? $cap) : $asked;
    }

    private function begin(): void
    {
        $this->start     = microtime(true);
        $this->requestId = 'req_' . bin2hex(random_bytes(12));
    }

    /** Scope, model (default), key allowlist, access roles, quota. Returns [model, errorResponse|null]. */
    private function resolveModel(string $scope, string $endpoint, array $in): array
    {
        if ($e = $this->scopeError($scope)) return ['', $e];
        $key   = $this->key();
        $reg   = new ModelRegistryService();
        $model = trim((string) ($in['model'] ?? ''));
        if ($model === '' || $model === 'default') {
            $model = (string) $reg->defaultFor($scope === 'generate' ? 'generate' : $scope, $key['application'] ?? null);
            if ($model === '' && $scope === 'generate') $model = (string) $reg->defaultFor('chat', $key['application'] ?? null);
        }
        if ($model === '') return ['', $this->error('model is required (no default model is set for this task).', 400, 'missing_required_parameter', 'model')];
        $allowed = ! empty($key['allowed_models']) ? json_decode($key['allowed_models'], true) : null;
        if (is_array($allowed) && $allowed && ! in_array($model, $allowed, true)) {
            return [$model, $this->failLogged($endpoint, $model, null, 403, "This API key may not use the model '{$model}'.", 'model_not_allowed')];
        }
        if (! $reg->roleAllowed($model, $reg->roleOf(isset($key['user_id']) ? (int) $key['user_id'] : null))) {
            return [$model, $this->failLogged($endpoint, $model, null, 403, "The model '{$model}' is not available to this key's owner.", 'model_not_allowed')];
        }
        if ($q = (new ApiGatewayService())->quotaError($key)) {
            return [$model, $this->failLogged($endpoint, $model, null, 429, $q, 'insufficient_quota')];
        }
        return [$model, null];
    }

    private function scopeError(string $scope)
    {
        $scopes = explode(',', (string) ($this->key()['scopes'] ?? ''));
        return in_array($scope, $scopes, true) ? null : $this->error("This API key lacks the '{$scope}' scope.", 403, 'insufficient_scope');
    }

    /**
     * OpenAI content → the gateway's message shape: text parts joined, data: URL images as base64.
     * Remote image URLs are refused (the gateway does not fetch URLs on a caller's behalf).
     */
    private function messages($raw): array
    {
        if (! is_array($raw) || ! $raw) throw new \InvalidArgumentException('messages must be a non-empty array.');
        $out = [];
        $callNames = []; // tool_call_id => tool name, from earlier assistant turns
        foreach ($raw as $i => $m) {
            $role = (string) ($m['role'] ?? '');
            if ($role === 'developer') $role = 'system';
            if (! in_array($role, ['system', 'user', 'assistant', 'tool'], true)) throw new \InvalidArgumentException("messages[{$i}].role '{$role}' is not supported.");
            $content = $m['content'] ?? '';
            $msg = ['role' => $role, 'content' => ''];
            if ($role === 'assistant' && ! empty($m['tool_calls'])) {
                if (! is_array($m['tool_calls'])) throw new \InvalidArgumentException("messages[{$i}].tool_calls must be an array.");
                foreach ($m['tool_calls'] as $j => $tc) {
                    $id = (string) ($tc['id'] ?? ''); $name = (string) ($tc['function']['name'] ?? '');
                    if ($id === '' || $name === '') throw new \InvalidArgumentException("messages[{$i}].tool_calls[{$j}] needs an id and function.name.");
                    $args = $tc['function']['arguments'] ?? '{}';
                    $msg['tool_calls'][] = ['id' => $id, 'name' => $name, 'arguments' => is_string($args) ? $args : json_encode($args)];
                    $callNames[$id] = $name;
                }
            }
            if ($role === 'tool') {
                $cid = (string) ($m['tool_call_id'] ?? '');
                if ($cid === '') throw new \InvalidArgumentException("messages[{$i}] (role tool) needs a tool_call_id.");
                $msg['tool_call_id'] = $cid;
                $msg['name'] = $callNames[$cid] ?? (string) ($m['name'] ?? ''); // Gemini and Ollama match results by tool name
            }
            if (is_string($content)) {
                $msg['content'] = $content;
            } elseif (is_array($content)) {
                $texts = [];
                foreach ($content as $part) {
                    $type = $part['type'] ?? '';
                    if ($type === 'text') {
                        $texts[] = (string) ($part['text'] ?? '');
                    } elseif ($type === 'image_url') {
                        $url = (string) ($part['image_url']['url'] ?? ($part['image_url'] ?? ''));
                        if (! preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $url, $mm)) {
                            throw new \InvalidArgumentException('Images must be sent as base64 data: URLs.');
                        }
                        $msg['images'][] = $mm[1];
                    } else {
                        throw new \InvalidArgumentException("messages[{$i}] content part type '{$type}' is not supported.");
                    }
                }
                $msg['content'] = implode("\n", $texts);
            } elseif ($content !== null) {
                throw new \InvalidArgumentException("messages[{$i}].content must be a string or an array of parts.");
            }
            $out[] = $msg;
        }
        return $out;
    }

    private function body(): ?array
    {
        $raw = (string) $this->request->getBody();
        if (trim($raw) === '') return [];
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function logCall(string $endpoint, string $model, ?int $serverId, int $status, ?int $pt, ?int $ct, ?string $error = null): void
    {
        $key = $this->key();
        (new ApiGatewayService())->log([
            'api_key_id' => $key['id'] ?? null, 'user_id' => $key['user_id'] ?? null, 'endpoint' => 'openai/' . $endpoint,
            'model' => $model ?: null, 'server_id' => $serverId, 'prompt_tokens' => $pt, 'completion_tokens' => $ct,
            'total_tokens' => ($pt === null && $ct === null) ? null : (int) $pt + (int) $ct,
            'processing_ms' => $this->start ? (int) round((microtime(true) - $this->start) * 1000) : null,
            'status' => $status, 'client_ip' => $this->request->getIPAddress(), 'error' => $error ? mb_substr($error, 0, 255) : null,
        ]);
    }

    private function failLogged(string $endpoint, string $model, ?int $serverId, int $status, string $message, ?string $code = null)
    {
        $this->logCall($endpoint, $model, $serverId, $status, null, null, $message);
        return $this->error($message, $status, $code);
    }

    private function openStream(): void
    {
        while (ob_get_level() > 0) ob_end_flush();
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('x-request-id: ' . $this->requestId);
        foreach ($this->rateHeaders() as $k => $v) header("{$k}: {$v}");
    }

    /** For streams, which exit before after-filters run (ApiKeyFilter::after adds them to other answers). */
    private function rateHeaders(): array
    {
        return $this->key() ? (new ApiGatewayService())->quotaHeaders($this->key()) : [];
    }

    private function json(array $body, int $status = 200)
    {
        $res = $this->response->setStatusCode($status)->setJSON($body);
        if ($this->requestId) $res->setHeader('x-request-id', $this->requestId);
        if ($this->start) $res->setHeader('openai-processing-ms', (string) (int) round((microtime(true) - $this->start) * 1000));
        return $res;
    }

    /** OpenAI error envelope. */
    public static function errorBody(string $message, int $status, ?string $code = null, ?string $param = null): array
    {
        $type = match (true) {
            $status === 401 => 'authentication_error',
            $status === 403 => 'permission_error',
            $status === 404 => 'not_found_error',
            $status === 429 => $code === 'insufficient_quota' ? 'insufficient_quota' : 'rate_limit_error',
            $status >= 500  => 'api_error',
            default         => 'invalid_request_error',
        };
        return ['error' => ['message' => $message, 'type' => $type, 'param' => $param, 'code' => $code]];
    }

    private function error(string $message, int $status, ?string $code = null, ?string $param = null)
    {
        return $this->json(self::errorBody($message, $status, $code, $param), $status);
    }
}
