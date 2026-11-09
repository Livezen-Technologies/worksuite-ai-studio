<?php

namespace App\Services;

/**
 * ProviderService — a unified streaming LLM client across self-hosted Ollama
 * nodes AND external commercial APIs (OpenAI, Anthropic/Claude, Google Gemini).
 *
 * A "server" row carries a `kind`:
 *   - ollama    → delegates to OllamaService (api_url = node URL, auth_token = bearer)
 *   - openai    → POST {base}/chat/completions      (auth_token = API key)
 *   - anthropic → POST {base}/messages              (auth_token = API key)
 *   - gemini    → POST {base}/models/{m}:streamGenerateContent (auth_token = API key)
 *
 * Messages are passed in Ollama shape: [['role'=>.., 'content'=>.., 'images'=>[b64]]].
 * Each provider adapter translates that to its own wire format, streams the
 * reply token-by-token to $onDelta(string), and returns normalised usage:
 *   ['prompt_tokens'=>?int, 'completion_tokens'=>?int, 'total_ms'=>int].
 */
class ProviderService
{
    /** Kinds that are external cloud APIs rather than Ollama nodes. */
    public const EXTERNAL = ['openai', 'anthropic', 'gemini'];

    /** Self-hosted inference engines that speak the OpenAI API (base URL …/v1). */
    public const OPENAI_ENGINES = ['vllm', 'llamacpp', 'openai_compatible'];

    /** Every kind a server row may carry. */
    public const KINDS = ['ollama', 'vllm', 'llamacpp', 'openai_compatible', 'openai', 'anthropic', 'gemini'];

    /** Default base URLs per external kind (used when api_url is blank). */
    private const DEFAULT_BASE = [
        'openai'    => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com/v1',
        'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
    ];

    public const LABELS = ['vllm' => 'vLLM', 'llamacpp' => 'llama.cpp', 'openai_compatible' => 'OpenAI-compatible'];

    /** Curated fallbacks shown when a provider's model list can't be fetched. */
    private const FALLBACK_MODELS = [
        'openai'    => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4-turbo', 'gpt-3.5-turbo'],
        'anthropic' => ['claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5'],
        'gemini'    => ['gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash'],
    ];

    public function isExternal(string $kind): bool
    {
        return in_array($kind, self::EXTERNAL, true);
    }

    /** True for Ollama nodes (native /api/* protocol); false for anything spoken to over an OpenAI-style or vendor API. */
    public function isOllama(string $kind): bool
    {
        return ! in_array($kind, self::EXTERNAL, true) && ! in_array($kind, self::OPENAI_ENGINES, true);
    }

    private function timeout(): int
    {
        return (int) env('ollama.timeout', 300);
    }

    private function base(array $server): string
    {
        $kind = $server['kind'] ?? 'ollama';
        $url  = trim((string) ($server['api_url'] ?? ''));
        if ($url === '') {
            $url = self::DEFAULT_BASE[$kind] ?? '';
        }
        $url = rtrim($url, '/');
        // vLLM / llama.cpp servers serve the OpenAI API under /v1; accept the bare host:port too.
        if (in_array($kind, self::OPENAI_ENGINES, true) && ! preg_match('#/v\d+$#', $url)) {
            $url .= '/v1';
        }
        return $url;
    }

    // ---- Chat --------------------------------------------------------------

    /**
     * Streaming chat across any provider kind.
     *
     * @param array    $extra   Ollama options/keep_alive (ignored by external kinds).
     * @param callable $onDelta fn(string $text): void — called per token.
     * @return array{prompt_tokens:?int,completion_tokens:?int,total_ms:int}
     */
    public function chat(array $server, string $model, array $messages, array $extra, callable $onDelta): array
    {
        $kind  = $server['kind'] ?? 'ollama';
        $start = microtime(true);
        // Provider-neutral generation parameters (OpenAI names): temperature, top_p, max_tokens, stop, seed,
        // presence_penalty, frequency_penalty, json (bool). Each adapter maps them to its own wire format.
        $this->params = self::cleanParams((array) ($extra['params'] ?? []));
        // Tool calling (OpenAI format in, OpenAI format out): $extra['tools'], $extra['tool_choice'].
        $this->tools      = self::normalizeTools((array) ($extra['tools'] ?? []));
        $this->toolChoice = self::normalizeToolChoice($extra['tool_choice'] ?? null, $this->tools);
        if ($this->toolChoice === 'none') $this->tools = [];
        $this->toolCalls  = [];
        unset($extra['params'], $extra['tools'], $extra['tool_choice']);
        if ($this->params && $this->isOllama($kind)) {
            $map = ['temperature' => 'temperature', 'top_p' => 'top_p', 'max_tokens' => 'num_predict', 'stop' => 'stop', 'seed' => 'seed',
                    'presence_penalty' => 'presence_penalty', 'frequency_penalty' => 'frequency_penalty'];
            foreach ($map as $from => $to) if (array_key_exists($from, $this->params)) $extra['options'][$to] = $this->params[$from];
            if (! empty($this->params['json'])) $extra['format'] = 'json';
        }

        $out = match ($kind) {
            'openai', 'vllm', 'llamacpp', 'openai_compatible' => $this->openaiChat($server, $model, $messages, $onDelta, $start),
            'anthropic' => $this->anthropicChat($server, $model, $messages, $onDelta, $start),
            'gemini'    => $this->geminiChat($server, $model, $messages, $onDelta, $start),
            default     => $this->ollamaChat($server, $model, $messages, $extra, $onDelta, $start),
        };
        $out['tool_calls'] = array_values($this->toolCalls);
        return $out;
    }

    /** Generation parameters for the current chat() call (see chat()). */
    private array $params = [];
    /** Tools for the current call: [{name, description, parameters}]. */
    private array $tools = [];
    /** 'auto' | 'required' | 'none' | ['name' => string] */
    private $toolChoice = 'auto';
    /** Tool calls the model made in the current call: index => {id, name, arguments (JSON string)}. */
    private array $toolCalls = [];

    /**
     * OpenAI tools ([{type: "function", function: {name, description, parameters}}]) → [{name, description, parameters}].
     *
     * @throws \InvalidArgumentException on a malformed tool
     */
    public static function normalizeTools(array $tools): array
    {
        $out = [];
        foreach (array_values($tools) as $i => $t) {
            $f = $t['function'] ?? null;
            if (($t['type'] ?? 'function') !== 'function' || ! is_array($f)) throw new \InvalidArgumentException("tools[{$i}] must be {type: \"function\", function: {...}}.");
            $name = (string) ($f['name'] ?? '');
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) throw new \InvalidArgumentException("tools[{$i}].function.name must be 1-64 letters, digits, _ or -.");
            $params = $f['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            if (! is_array($params) && ! is_object($params)) throw new \InvalidArgumentException("tools[{$i}].function.parameters must be a JSON schema object.");
            $out[] = ['name' => $name, 'description' => (string) ($f['description'] ?? ''), 'parameters' => $params];
        }
        if (count($out) > 128) throw new \InvalidArgumentException('At most 128 tools per request.');
        return $out;
    }

    /** 'auto' | 'none' | 'required' | {type: "function", function: {name}} → 'auto'|'none'|'required'|['name'=>…]. */
    public static function normalizeToolChoice($choice, array $tools)
    {
        if ($choice === null || $choice === 'auto') return 'auto';
        if ($choice === 'none' || $choice === 'required') return $choice;
        $name = is_array($choice) ? (string) ($choice['function']['name'] ?? '') : '';
        if ($name === '' || ! in_array($name, array_column($tools, 'name'), true)) {
            throw new \InvalidArgumentException('tool_choice must be "auto", "none", "required" or name one of the tools.');
        }
        return ['name' => $name];
    }

    private static function callId(): string
    {
        return 'call_' . bin2hex(random_bytes(12));
    }

    /** Arguments as a JSON object string, whatever shape the provider used. */
    private static function argsJson($args): string
    {
        if (is_string($args)) return $args === '' ? '{}' : $args;
        return json_encode($args === null || $args === [] ? new \stdClass() : $args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Arguments as an array/object for providers that want structured input. */
    private static function argsObject(string $json)
    {
        $v = json_decode($json === '' ? '{}' : $json, false);
        return is_object($v) ? $v : new \stdClass();
    }

    /** Gemini accepts only a subset of JSON Schema; drop the keywords it rejects. */
    private static function geminiSchema($schema)
    {
        if (is_object($schema)) $schema = (array) $schema;
        if (! is_array($schema)) return $schema;
        $out = [];
        foreach ($schema as $k => $v) {
            if (in_array($k, ['$schema', 'additionalProperties', '$id', '$defs', 'definitions', 'default', 'examples', 'title', 'strict'], true)) continue;
            $out[$k] = is_array($v) || is_object($v) ? self::geminiSchema($v) : $v;
        }
        if (($out['type'] ?? null) === 'object' && empty($out['properties'])) unset($out['properties']);
        return $out;
    }

    public static function cleanParams(array $p): array
    {
        $out = [];
        foreach (['temperature' => [0, 2], 'top_p' => [0, 1], 'presence_penalty' => [-2, 2], 'frequency_penalty' => [-2, 2]] as $k => [$lo, $hi]) {
            if (isset($p[$k]) && is_numeric($p[$k])) $out[$k] = max($lo, min($hi, (float) $p[$k]));
        }
        if (isset($p['max_tokens']) && (int) $p['max_tokens'] > 0) $out['max_tokens'] = min(1000000, (int) $p['max_tokens']);
        if (isset($p['seed']) && is_numeric($p['seed'])) $out['seed'] = (int) $p['seed'];
        if (isset($p['stop'])) {
            $stop = array_values(array_filter(array_map('strval', (array) $p['stop']), static fn ($x) => $x !== ''));
            if ($stop) $out['stop'] = array_slice($stop, 0, 4);
        }
        if (! empty($p['json'])) $out['json'] = true;
        return $out;
    }
    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function ollamaChat(array $server, string $model, array $messages, array $extra, callable $onDelta, float $start): array
    {
        if ($this->tools) {
            $tools = $this->tools;
            if (is_array($this->toolChoice)) $tools = array_values(array_filter($tools, fn ($t) => $t['name'] === $this->toolChoice['name']));
            $extra['tools'] = array_map(static fn ($t) => ['type' => 'function', 'function' => $t], $tools);
            if ($this->toolChoice !== 'auto') { // Ollama has no tool_choice: say it in a system message
                array_unshift($messages, ['role' => 'system', 'content' => 'You must answer by calling ' . (is_array($this->toolChoice) ? "the tool {$this->toolChoice['name']}" : 'one of the tools') . '.']);
            }
        }
        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null);
        $final  = $client->chat($model, array_map([$this, 'ollamaMessage'], $messages), $extra, function (array $line) use ($onDelta) {
            $d = $line['message']['content'] ?? '';
            if ($d !== '') {
                $onDelta($d);
            }
            foreach ($line['message']['tool_calls'] ?? [] as $tc) {
                $this->toolCalls[] = ['id' => self::callId(), 'name' => (string) ($tc['function']['name'] ?? ''), 'arguments' => self::argsJson($tc['function']['arguments'] ?? null)];
            }
        });
        return [
            'prompt_tokens'     => $final['prompt_eval_count'] ?? null,
            'completion_tokens' => $final['eval_count'] ?? null,
            'total_ms'          => isset($final['total_duration'])
                ? (int) ($final['total_duration'] / 1e6)
                : $this->elapsed($start),
        ];
    }

    private function openaiChat(array $server, string $model, array $messages, callable $onDelta, float $start): array
    {
        $body = [
            'model'          => $model,
            'messages'       => array_map([$this, 'openaiMessage'], $messages),
            'stream'         => true,
            'stream_options' => ['include_usage' => true],
        ];
        foreach (['temperature', 'top_p', 'max_tokens', 'stop', 'seed', 'presence_penalty', 'frequency_penalty'] as $k) {
            if (array_key_exists($k, $this->params)) $body[$k] = $this->params[$k];
        }
        if (! empty($this->params['json'])) $body['response_format'] = ['type' => 'json_object'];
        if ($this->tools) {
            $body['tools'] = array_map(static fn ($t) => ['type' => 'function', 'function' => $t], $this->tools);
            $body['tool_choice'] = is_array($this->toolChoice) ? ['type' => 'function', 'function' => ['name' => $this->toolChoice['name']]] : $this->toolChoice;
        }
        $usage = ['p' => null, 'c' => null];
        $headers = ['Content-Type: application/json'];
        if (! empty($server['auth_token']) || ($server['kind'] ?? '') === 'openai') $headers[] = 'Authorization: Bearer ' . ($server['auth_token'] ?? '');
        $this->sse($this->base($server) . '/chat/completions', $headers, $body, function (array $j) use ($onDelta, &$usage) {
            $delta = $j['choices'][0]['delta'] ?? [];
            $d = $delta['content'] ?? '';
            if ($d !== '' && $d !== null) {
                $onDelta($d);
            }
            foreach ($delta['tool_calls'] ?? [] as $tc) { // streamed in pieces, keyed by index
                $i = (int) ($tc['index'] ?? 0);
                $this->toolCalls[$i] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                if (! empty($tc['id'])) $this->toolCalls[$i]['id'] = $tc['id'];
                if (! empty($tc['function']['name'])) $this->toolCalls[$i]['name'] .= $tc['function']['name'];
                if (isset($tc['function']['arguments'])) $this->toolCalls[$i]['arguments'] .= $tc['function']['arguments'];
            }
            if (isset($j['usage'])) {
                $usage['p'] = $j['usage']['prompt_tokens'] ?? $usage['p'];
                $usage['c'] = $j['usage']['completion_tokens'] ?? $usage['c'];
            }
        });
        ksort($this->toolCalls);
        foreach ($this->toolCalls as &$tc) {
            $tc['id'] = $tc['id'] ?: self::callId();
            $tc['arguments'] = $tc['arguments'] === '' ? '{}' : $tc['arguments'];
        }
        unset($tc);
        return ['prompt_tokens' => $usage['p'], 'completion_tokens' => $usage['c'], 'total_ms' => $this->elapsed($start)];
    }

    private function anthropicChat(array $server, string $model, array $messages, callable $onDelta, float $start): array
    {
        $system = '';
        $msgs   = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= ($system !== '' ? "\n\n" : '') . ($m['content'] ?? '');
                continue;
            }
            $msg = $this->anthropicMessage($m);
            // Anthropic wants strictly alternating roles: merge consecutive same-role turns (e.g. several tool results).
            $last = count($msgs) - 1;
            if ($last >= 0 && $msgs[$last]['role'] === $msg['role']) {
                $toBlocks = static fn ($c) => is_array($c) ? $c : ($c === '' ? [] : [['type' => 'text', 'text' => $c]]);
                $msgs[$last]['content'] = array_merge($toBlocks($msgs[$last]['content']), $toBlocks($msg['content']));
            } else {
                $msgs[] = $msg;
            }
        }
        $body = ['model' => $model, 'max_tokens' => $this->params['max_tokens'] ?? 4096, 'messages' => $msgs, 'stream' => true];
        if (isset($this->params['temperature'])) $body['temperature'] = min(1.0, $this->params['temperature']);
        if (isset($this->params['top_p'])) $body['top_p'] = $this->params['top_p'];
        if (isset($this->params['stop'])) $body['stop_sequences'] = $this->params['stop'];
        if ($system !== '') {
            $body['system'] = $system;
        }
        if ($this->tools) {
            $body['tools'] = array_map(static fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $this->tools);
            $body['tool_choice'] = is_array($this->toolChoice) ? ['type' => 'tool', 'name' => $this->toolChoice['name']]
                : ['type' => $this->toolChoice === 'required' ? 'any' : 'auto'];
        }
        $usage  = ['p' => null, 'c' => null];
        $blocks = []; // content block index → tool call index
        $this->sse($this->base($server) . '/messages', [
            'Content-Type: application/json',
            'x-api-key: ' . ($server['auth_token'] ?? ''),
            'anthropic-version: 2023-06-01',
        ], $body, function (array $j) use ($onDelta, &$usage, &$blocks) {
            $t = $j['type'] ?? '';
            if ($t === 'content_block_start' && (($j['content_block']['type'] ?? '') === 'tool_use')) {
                $blocks[(int) $j['index']] = count($this->toolCalls);
                $this->toolCalls[] = ['id' => (string) ($j['content_block']['id'] ?? self::callId()), 'name' => (string) ($j['content_block']['name'] ?? ''), 'arguments' => ''];
            } elseif ($t === 'content_block_delta' && (($j['delta']['type'] ?? '') === 'text_delta')) {
                $onDelta($j['delta']['text'] ?? '');
            } elseif ($t === 'content_block_delta' && (($j['delta']['type'] ?? '') === 'input_json_delta') && isset($blocks[(int) $j['index']])) {
                $this->toolCalls[$blocks[(int) $j['index']]]['arguments'] .= (string) ($j['delta']['partial_json'] ?? '');
            } elseif ($t === 'message_start') {
                $usage['p'] = $j['message']['usage']['input_tokens'] ?? $usage['p'];
            } elseif ($t === 'message_delta') {
                $usage['c'] = $j['usage']['output_tokens'] ?? $usage['c'];
            }
        });
        foreach ($this->toolCalls as &$tc) $tc['arguments'] = $tc['arguments'] === '' ? '{}' : $tc['arguments'];
        unset($tc);
        return ['prompt_tokens' => $usage['p'], 'completion_tokens' => $usage['c'], 'total_ms' => $this->elapsed($start)];
    }

    private function geminiChat(array $server, string $model, array $messages, callable $onDelta, float $start): array
    {
        $sys      = '';
        $contents = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $sys .= ($sys !== '' ? "\n\n" : '') . ($m['content'] ?? '');
                continue;
            }
            $c = $this->geminiContent($m);
            $last = count($contents) - 1;
            if ($last >= 0 && $contents[$last]['role'] === $c['role'] && ($m['role'] ?? '') === 'tool') {
                $contents[$last]['parts'] = array_merge($contents[$last]['parts'], $c['parts']); // parallel tool results in one turn
            } else {
                $contents[] = $c;
            }
        }
        $body = ['contents' => $contents];
        $gc = array_filter([
            'temperature' => $this->params['temperature'] ?? null, 'topP' => $this->params['top_p'] ?? null,
            'maxOutputTokens' => $this->params['max_tokens'] ?? null, 'stopSequences' => $this->params['stop'] ?? null,
            'seed' => $this->params['seed'] ?? null, 'responseMimeType' => ! empty($this->params['json']) ? 'application/json' : null,
        ], static fn ($v) => $v !== null);
        if ($gc) $body['generationConfig'] = $gc;
        if ($sys !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $sys]]];
        }
        if ($this->tools) {
            $body['tools'] = [['functionDeclarations' => array_map(static fn ($t) => array_filter([
                'name' => $t['name'], 'description' => $t['description'] ?: null, 'parameters' => self::geminiSchema($t['parameters']) ?: null,
            ]), $this->tools)]];
            $fc = ['mode' => $this->toolChoice === 'auto' ? 'AUTO' : 'ANY'];
            if (is_array($this->toolChoice)) $fc['allowedFunctionNames'] = [$this->toolChoice['name']];
            $body['toolConfig'] = ['functionCallingConfig' => $fc];
        }
        $url = $this->base($server) . '/models/' . rawurlencode($model)
            . ':streamGenerateContent?alt=sse&key=' . urlencode((string) ($server['auth_token'] ?? ''));
        $usage = ['p' => null, 'c' => null];
        $this->sse($url, ['Content-Type: application/json'], $body, function (array $j) use ($onDelta, &$usage) {
            foreach ($j['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && $part['text'] !== '') {
                    $onDelta($part['text']);
                }
                if (isset($part['functionCall'])) {
                    $this->toolCalls[] = ['id' => self::callId(), 'name' => (string) ($part['functionCall']['name'] ?? ''), 'arguments' => self::argsJson($part['functionCall']['args'] ?? null)];
                }
            }
            if (isset($j['usageMetadata'])) {
                $usage['p'] = $j['usageMetadata']['promptTokenCount'] ?? $usage['p'];
                $usage['c'] = $j['usageMetadata']['candidatesTokenCount'] ?? $usage['c'];
            }
        });
        return ['prompt_tokens' => $usage['p'], 'completion_tokens' => $usage['c'], 'total_ms' => $this->elapsed($start)];
    }

    // ---- Message translation (gateway shape → provider shape) ---------------
    //
    // Gateway shape: {role: system|user|assistant|tool, content, images?: [b64],
    //                 tool_calls?: [{id, name, arguments}] (assistant), tool_call_id?, name? (tool)}

    private function ollamaMessage(array $m): array
    {
        $role = $m['role'] ?? 'user';
        if ($role === 'tool') {
            return ['role' => 'tool', 'content' => (string) ($m['content'] ?? ''), 'tool_name' => (string) ($m['name'] ?? '')];
        }
        $out = ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
        if (! empty($m['images'])) $out['images'] = $m['images'];
        if (! empty($m['tool_calls'])) {
            $out['tool_calls'] = array_map(static fn ($tc) => ['function' => ['name' => $tc['name'], 'arguments' => self::argsObject($tc['arguments'])]], $m['tool_calls']);
        }
        return $out;
    }

    private function openaiMessage(array $m): array
    {
        $r = $m['role'] ?? 'user';
        if ($r === 'tool') {
            return ['role' => 'tool', 'tool_call_id' => (string) ($m['tool_call_id'] ?? ''), 'content' => (string) ($m['content'] ?? '')];
        }
        $role = $r === 'system' ? 'system' : ($r === 'assistant' ? 'assistant' : 'user');
        if ($role === 'assistant' && ! empty($m['tool_calls'])) {
            return ['role' => 'assistant', 'content' => ($m['content'] ?? '') === '' ? null : (string) $m['content'],
                'tool_calls' => array_map(static fn ($tc) => ['id' => $tc['id'], 'type' => 'function', 'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']]], $m['tool_calls'])];
        }
        $imgs = $m['images'] ?? [];
        if (! $imgs) {
            return ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
        }
        $content = [['type' => 'text', 'text' => (string) ($m['content'] ?? '')]];
        foreach ($imgs as $b64) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $this->mime($b64) . ';base64,' . $b64]];
        }
        return ['role' => $role, 'content' => $content];
    }

    private function anthropicMessage(array $m): array
    {
        $r = $m['role'] ?? 'user';
        if ($r === 'tool') {
            return ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => (string) ($m['tool_call_id'] ?? ''), 'content' => (string) ($m['content'] ?? '')]]];
        }
        $role = $r === 'assistant' ? 'assistant' : 'user';
        if ($role === 'assistant' && ! empty($m['tool_calls'])) {
            $content = ($m['content'] ?? '') !== '' ? [['type' => 'text', 'text' => (string) $m['content']]] : [];
            foreach ($m['tool_calls'] as $tc) $content[] = ['type' => 'tool_use', 'id' => $tc['id'], 'name' => $tc['name'], 'input' => self::argsObject($tc['arguments'])];
            return ['role' => 'assistant', 'content' => $content];
        }
        $imgs = $m['images'] ?? [];
        if (! $imgs) {
            return ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
        }
        $content = [];
        foreach ($imgs as $b64) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $this->mime($b64), 'data' => $b64]];
        }
        $content[] = ['type' => 'text', 'text' => (string) ($m['content'] ?? '')];
        return ['role' => $role, 'content' => $content];
    }

    private function geminiContent(array $m): array
    {
        $r = $m['role'] ?? 'user';
        if ($r === 'tool') {
            $res = json_decode((string) ($m['content'] ?? ''), true);
            return ['role' => 'user', 'parts' => [['functionResponse' => [
                'name' => (string) ($m['name'] ?? ''), 'response' => is_array($res) && ! array_is_list($res) ? $res : ['content' => $m['content'] ?? ''],
            ]]]];
        }
        $role  = $r === 'assistant' ? 'model' : 'user';
        $parts = ($m['content'] ?? '') !== '' || empty($m['tool_calls']) ? [['text' => (string) ($m['content'] ?? '')]] : [];
        foreach ($m['images'] ?? [] as $b64) {
            $parts[] = ['inlineData' => ['mimeType' => $this->mime($b64), 'data' => $b64]];
        }
        foreach ($m['tool_calls'] ?? [] as $tc) {
            $parts[] = ['functionCall' => ['name' => $tc['name'], 'args' => self::argsObject($tc['arguments'])]];
        }
        return ['role' => $role, 'parts' => $parts];
    }

    /** Best-effort MIME sniff from a base64 payload's leading bytes. */
    private function mime(string $b64): string
    {
        return match (true) {
            str_starts_with($b64, '/9j/')        => 'image/jpeg',
            str_starts_with($b64, 'iVBORw0KGgo') => 'image/png',
            str_starts_with($b64, 'R0lGOD')      => 'image/gif',
            str_starts_with($b64, 'UklGR')       => 'image/webp',
            default                              => 'image/png',
        };
    }

    // ---- Embeddings (gateway) ---------------------------------------------

    /** Embeddings across kinds. Returns ['embeddings'=>[[...],...]]. */
    public function embeddings(array $server, string $model, $input): array
    {
        $kind   = $server['kind'] ?? 'ollama';
        $inputs = is_array($input) ? array_values($input) : [(string) $input];

        if ($kind === 'openai' || in_array($kind, self::OPENAI_ENGINES, true)) {
            $res  = $this->jsonPost($this->base($server) . '/embeddings', [
                'Authorization: Bearer ' . ($server['auth_token'] ?? ''),
            ], ['model' => $model, 'input' => $inputs]);
            $vecs = array_map(static fn ($d) => $d['embedding'] ?? [], $res['data'] ?? []);
            return ['embeddings' => $vecs];
        }
        if ($kind === 'gemini') {
            $reqs = array_map(static fn ($t) => [
                'model' => 'models/' . $model,
                'content' => ['parts' => [['text' => $t]]],
            ], $inputs);
            $url  = $this->base($server) . '/models/' . rawurlencode($model)
                . ':batchEmbedContents?key=' . urlencode((string) ($server['auth_token'] ?? ''));
            $res  = $this->jsonPost($url, [], ['requests' => $reqs]);
            $vecs = array_map(static fn ($e) => $e['values'] ?? [], $res['embeddings'] ?? []);
            return ['embeddings' => $vecs];
        }
        if ($kind === 'anthropic') {
            throw new \RuntimeException('Anthropic does not provide an embeddings endpoint.');
        }
        // Ollama
        return (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->embeddings($model, $input);
    }

    // ---- Probe + model discovery ------------------------------------------

    /**
     * Reachability probe. Same shape as OllamaService::probe() so ServerService
     * can score it uniformly.
     */
    public function probe(array $server): array
    {
        $kind = $server['kind'] ?? 'ollama';
        if ($this->isOllama($kind)) {
            return (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->probe();
        }
        $start = microtime(true);
        try {
            $models = $this->listModels($server, true); // strict: throw on transport/auth error
            return [
                'ok'             => true,
                'latency_ms'     => $this->elapsed($start),
                'version'        => self::LABELS[$kind] ?? ucfirst($kind),
                'models_total'   => count($models),
                'models_running' => 0,
                'error'          => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'             => false,
                'latency_ms'     => $this->elapsed($start),
                'version'        => null,
                'models_total'   => 0,
                'models_running' => 0,
                'error'          => $e->getMessage(),
            ];
        }
    }

    /**
     * Chat-capable model ids for an external provider. On any failure returns the
     * curated fallback list, unless $strict (then the transport error is thrown —
     * used by probe() so a bad key surfaces as offline).
     *
     * @return list<string>
     */
    public function listModels(array $server, bool $strict = false): array
    {
        $kind = $server['kind'] ?? 'ollama';
        try {
            return match ($kind) {
                'openai'    => $this->openaiModels($server),
                'anthropic' => $this->anthropicModels($server),
                'gemini'    => $this->geminiModels($server),
                'vllm', 'llamacpp', 'openai_compatible' => $this->engineModels($server),
                default     => [],
            };
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }
            return self::FALLBACK_MODELS[$kind] ?? [];
        }
    }

    private function openaiModels(array $server): array
    {
        $res = $this->jsonGet($this->base($server) . '/models', [
            'Authorization: Bearer ' . ($server['auth_token'] ?? ''),
        ]);
        $ids = array_map(static fn ($m) => $m['id'] ?? '', $res['data'] ?? []);
        $ids = array_values(array_filter($ids, static fn ($id) => (bool) preg_match('/^(gpt|o[0-9]|chatgpt)/i', $id)));
        sort($ids);
        return $ids ?: self::FALLBACK_MODELS['openai'];
    }

    /** Every model a self-hosted OpenAI-compatible engine serves (no vendor filter, no fallback list). */
    private function engineModels(array $server): array
    {
        $headers = ! empty($server['auth_token']) ? ['Authorization: Bearer ' . $server['auth_token']] : [];
        $res = $this->jsonGet($this->base($server) . '/models', $headers);
        $ids = array_values(array_filter(array_map(static fn ($m) => (string) ($m['id'] ?? ''), $res['data'] ?? [])));
        sort($ids);
        return $ids;
    }

    private function anthropicModels(array $server): array
    {
        $res = $this->jsonGet($this->base($server) . '/models', [
            'x-api-key: ' . ($server['auth_token'] ?? ''),
            'anthropic-version: 2023-06-01',
        ]);
        $ids = array_values(array_filter(array_map(static fn ($m) => $m['id'] ?? '', $res['data'] ?? [])));
        return $ids ?: self::FALLBACK_MODELS['anthropic'];
    }

    private function geminiModels(array $server): array
    {
        $res = $this->jsonGet($this->base($server) . '/models?key='
            . urlencode((string) ($server['auth_token'] ?? '')), []);
        $ids = [];
        foreach ($res['models'] ?? [] as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (! in_array('generateContent', $methods, true)) {
                continue;
            }
            $name  = (string) ($m['name'] ?? '');
            $ids[] = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
        }
        return $ids ?: self::FALLBACK_MODELS['gemini'];
    }

    // ---- HTTP helpers ------------------------------------------------------

    /**
     * Low-level SSE POST. Parses `data: {...}` lines and invokes $onJson per
     * decoded event. On an HTTP error the (non-SSE) body is captured and thrown
     * with the provider's error message so the user sees a useful reason.
     *
     * @throws \RuntimeException
     */
    private function sse(string $url, array $headers, array $body, callable $onJson): void
    {
        $ch     = curl_init($url);
        curl_setopt_array($ch, UrlGuard::curlOpts($url));
        $buffer = '';
        $rawHead = '';
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout(),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($_, $chunk) use (&$buffer, &$rawHead, $onJson) {
                if (strlen($rawHead) < 4096) {
                    $rawHead .= $chunk;
                }
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line   = trim(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);
                    if ($line === '' || strncmp($line, 'data:', 5) !== 0) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $j = json_decode($data, true);
                    if (is_array($j)) {
                        $onJson($j);
                    }
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Provider request failed: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException($this->extractError($rawHead, $code));
        }
    }

    private function jsonGet(string $url, array $headers): array
    {
        return $this->httpJson('GET', $url, $headers, null);
    }

    private function jsonPost(string $url, array $headers, array $body): array
    {
        return $this->httpJson('POST', $url, $headers, $body);
    }

    /**
     * Plain JSON request. Throws on transport or HTTP >= 400 with the provider's
     * error message.
     *
     * @throws \RuntimeException
     */
    private function httpJson(string $method, string $url, array $headers, ?array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, UrlGuard::curlOpts($url));
        $headers[] = 'Accept: application/json';
        $opts = [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 30,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER]  = $headers;
            $opts[CURLOPT_POSTFIELDS]  = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            throw new \RuntimeException('Provider unreachable: ' . ($err ?: 'no response'));
        }
        if ($code >= 400) {
            throw new \RuntimeException($this->extractError((string) $raw, $code));
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Pull a human error message out of a provider error body. */
    private function extractError(string $raw, int $code): string
    {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $msg = $j['error']['message']     // OpenAI / Gemini
                ?? $j['error']['type']        // some shapes
                ?? (is_string($j['error'] ?? null) ? $j['error'] : null)
                ?? $j['message']              // Anthropic-style
                ?? null;
            if (is_string($msg) && $msg !== '') {
                return "Provider error (HTTP {$code}): {$msg}";
            }
        }
        return "Provider returned HTTP {$code}.";
    }
}
