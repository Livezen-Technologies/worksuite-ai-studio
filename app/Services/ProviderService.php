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

    /** Default base URLs per external kind (used when api_url is blank). */
    private const DEFAULT_BASE = [
        'openai'    => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com/v1',
        'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
    ];

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
        return rtrim($url, '/');
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

        return match ($kind) {
            'openai'    => $this->openaiChat($server, $model, $messages, $onDelta, $start),
            'anthropic' => $this->anthropicChat($server, $model, $messages, $onDelta, $start),
            'gemini'    => $this->geminiChat($server, $model, $messages, $onDelta, $start),
            default     => $this->ollamaChat($server, $model, $messages, $extra, $onDelta, $start),
        };
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function ollamaChat(array $server, string $model, array $messages, array $extra, callable $onDelta, float $start): array
    {
        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null);
        $final  = $client->chat($model, $messages, $extra, static function (array $line) use ($onDelta) {
            $d = $line['message']['content'] ?? '';
            if ($d !== '') {
                $onDelta($d);
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
        $usage = ['p' => null, 'c' => null];
        $this->sse($this->base($server) . '/chat/completions', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($server['auth_token'] ?? ''),
        ], $body, static function (array $j) use ($onDelta, &$usage) {
            $d = $j['choices'][0]['delta']['content'] ?? '';
            if ($d !== '') {
                $onDelta($d);
            }
            if (isset($j['usage'])) {
                $usage['p'] = $j['usage']['prompt_tokens'] ?? $usage['p'];
                $usage['c'] = $j['usage']['completion_tokens'] ?? $usage['c'];
            }
        });
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
            $msgs[] = $this->anthropicMessage($m);
        }
        $body = ['model' => $model, 'max_tokens' => 4096, 'messages' => $msgs, 'stream' => true];
        if ($system !== '') {
            $body['system'] = $system;
        }
        $usage = ['p' => null, 'c' => null];
        $this->sse($this->base($server) . '/messages', [
            'Content-Type: application/json',
            'x-api-key: ' . ($server['auth_token'] ?? ''),
            'anthropic-version: 2023-06-01',
        ], $body, static function (array $j) use ($onDelta, &$usage) {
            $t = $j['type'] ?? '';
            if ($t === 'content_block_delta' && (($j['delta']['type'] ?? '') === 'text_delta')) {
                $onDelta($j['delta']['text'] ?? '');
            } elseif ($t === 'message_start') {
                $usage['p'] = $j['message']['usage']['input_tokens'] ?? $usage['p'];
            } elseif ($t === 'message_delta') {
                $usage['c'] = $j['usage']['output_tokens'] ?? $usage['c'];
            }
        });
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
            $contents[] = $this->geminiContent($m);
        }
        $body = ['contents' => $contents];
        if ($sys !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $sys]]];
        }
        $url = $this->base($server) . '/models/' . rawurlencode($model)
            . ':streamGenerateContent?alt=sse&key=' . urlencode((string) ($server['auth_token'] ?? ''));
        $usage = ['p' => null, 'c' => null];
        $this->sse($url, ['Content-Type: application/json'], $body, static function (array $j) use ($onDelta, &$usage) {
            foreach ($j['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && $part['text'] !== '') {
                    $onDelta($part['text']);
                }
            }
            if (isset($j['usageMetadata'])) {
                $usage['p'] = $j['usageMetadata']['promptTokenCount'] ?? $usage['p'];
                $usage['c'] = $j['usageMetadata']['candidatesTokenCount'] ?? $usage['c'];
            }
        });
        return ['prompt_tokens' => $usage['p'], 'completion_tokens' => $usage['c'], 'total_ms' => $this->elapsed($start)];
    }

    // ---- Message translation (Ollama shape → provider shape) ---------------

    private function openaiMessage(array $m): array
    {
        $role = ($m['role'] ?? 'user') === 'system' ? 'system' : (($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user');
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
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
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
        $role  = ($m['role'] ?? '') === 'assistant' ? 'model' : 'user';
        $parts = [['text' => (string) ($m['content'] ?? '')]];
        foreach ($m['images'] ?? [] as $b64) {
            $parts[] = ['inlineData' => ['mimeType' => $this->mime($b64), 'data' => $b64]];
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

        if ($kind === 'openai') {
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
        if (! $this->isExternal($kind)) {
            return (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->probe();
        }
        $start = microtime(true);
        try {
            $models = $this->listModels($server, true); // strict: throw on transport/auth error
            return [
                'ok'             => true,
                'latency_ms'     => $this->elapsed($start),
                'version'        => ucfirst($kind),
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
