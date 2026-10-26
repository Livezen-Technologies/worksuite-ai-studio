<?php

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;

/**
 * OllamaService — thin HTTP client for a single Ollama node's REST API.
 *
 *   $svc = new OllamaService('http://host:11434', $authToken);
 *   $svc->version(); $svc->tags(); $svc->ps(); $svc->show($model);
 *
 * Non-streaming endpoints return decoded arrays. Streaming endpoints
 * (pull/generate/chat) are added in Phases 3–4 using raw curl write callbacks.
 */
class OllamaService
{
    protected string $base;
    protected ?string $token;
    protected int $timeout;

    public function __construct(string $apiUrl, ?string $token = null, ?int $timeout = null)
    {
        $this->base    = rtrim($apiUrl, '/');
        $this->token   = $token ?: null;
        $this->timeout = $timeout ?? (int) env('ollama.timeout', 300);
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** GET /api/version — also the cheapest reachability probe. */
    public function version(): array
    {
        return $this->request('get', '/api/version');
    }

    /** GET /api/tags — installed models. Returns ['models' => [...]]. */
    public function tags(): array
    {
        return $this->request('get', '/api/tags');
    }

    /** GET /api/ps — currently loaded/running models. */
    public function ps(): array
    {
        return $this->request('get', '/api/ps');
    }

    /** POST /api/show — model metadata (modelfile, parameters, details). */
    public function show(string $model): array
    {
        return $this->request('post', '/api/show', ['model' => $model]);
    }

    /** POST /api/delete — remove a model from the node. */
    public function delete(string $model): array
    {
        return $this->request('delete', '/api/delete', ['model' => $model]);
    }

    /**
     * POST /api/pull — download a model. When $onProgress is given the NDJSON
     * progress stream is parsed and each decoded line is passed to it
     * (e.g. ['status' => 'pulling ...', 'completed' => N, 'total' => M]); the
     * method returns the final line. Without a callback it pulls non-streaming.
     */
    public function pull(string $model, ?callable $onProgress = null): array
    {
        if ($onProgress === null) {
            return $this->request('post', '/api/pull', ['model' => $model, 'stream' => false]);
        }
        return $this->stream('/api/pull', ['model' => $model, 'stream' => true], $onProgress);
    }

    /**
     * POST /api/create — build a custom model on the node (structured form:
     * from/system/template/parameters/files/adapters/quantize). Streams NDJSON
     * status lines to $onProgress and returns the final line.
     */
    public function createModel(array $spec, callable $onProgress): array
    {
        $spec['stream'] = true;
        return $this->stream('/api/create', $spec, $onProgress);
    }

    /** POST /api/copy — clone a model under a new name. */
    public function copy(string $source, string $destination): array
    {
        return $this->request('post', '/api/copy', ['source' => $source, 'destination' => $destination]);
    }

    /** HEAD /api/blobs/sha256:<hex> — does the node already have this blob? */
    public function blobExists(string $sha256): bool
    {
        $ch = curl_init($this->base . '/api/blobs/sha256:' . $sha256);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $this->token ? ['Authorization: Bearer ' . $this->token] : [],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    /**
     * POST /api/blobs/sha256:<hex> — upload a file (GGUF / LoRA adapter) to the
     * node's blob store. Streams from disk, so large files don't exhaust memory.
     *
     * @throws \RuntimeException on transport/HTTP error
     */
    public function pushBlob(string $sha256, string $filePath): void
    {
        $fh = fopen($filePath, 'rb');
        if (! $fh) {
            throw new \RuntimeException('Cannot open upload file.');
        }
        $ch = curl_init($this->base . '/api/blobs/sha256:' . $sha256);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => filesize($filePath),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 0, // large blobs
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/octet-stream'],
                $this->token ? ['Authorization: Bearer ' . $this->token] : [],
            ),
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($err !== '') {
            throw new \RuntimeException('Blob upload failed: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException("Blob upload rejected (HTTP {$code}): " . substr((string) $raw, 0, 200));
        }
    }

    /** POST /api/embed — embeddings for one or many inputs. */
    public function embeddings(string $model, $input): array
    {
        return $this->request('post', '/api/embed', ['model' => $model, 'input' => $input]);
    }

    /** Load a model into memory (keep_alive: -1 keeps it resident). */
    public function load(string $model): array
    {
        return $this->request('post', '/api/generate', ['model' => $model, 'keep_alive' => -1]);
    }

    /** Unload a model from memory immediately (keep_alive: 0). */
    public function unload(string $model): array
    {
        return $this->request('post', '/api/generate', ['model' => $model, 'keep_alive' => 0]);
    }

    /**
     * Streaming chat completion. $messages is [['role'=>..,'content'=>..], ...].
     * $extra may carry 'options', 'system' (folded into messages by the caller),
     * and 'keep_alive'. Each NDJSON line is passed to $onLine; returns the last line.
     */
    public function chat(string $model, array $messages, array $extra, callable $onLine): array
    {
        $body = array_merge(['model' => $model, 'messages' => $messages, 'stream' => true], $extra);
        return $this->stream('/api/chat', $body, $onLine);
    }

    /** Streaming single-prompt generation. */
    public function generate(string $model, string $prompt, array $extra, callable $onLine): array
    {
        $body = array_merge(['model' => $model, 'prompt' => $prompt, 'stream' => true], $extra);
        return $this->stream('/api/generate', $body, $onLine);
    }

    /**
     * Low-level NDJSON streaming POST. Invokes $onLine($decodedArray) for each
     * complete JSON line as it arrives. Returns the last decoded line.
     *
     * @throws \RuntimeException on transport error
     */
    public function stream(string $path, array $body, callable $onLine): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Content-Type: application/json', 'Accept: application/x-ndjson'];
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $buffer = '';
        $last   = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($_, $chunk) use (&$buffer, &$last, $onLine) {
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    $line   = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $last = $decoded;
                        $onLine($decoded);
                    }
                }
                return strlen($chunk);
            },
        ]);

        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($ok === false && $err !== '') {
            throw new \RuntimeException('Stream failed: ' . $err);
        }
        // Flush any trailing partial line.
        $line = trim($buffer);
        if ($line !== '' && ($decoded = json_decode($line, true)) && is_array($decoded)) {
            $last = $decoded;
            $onLine($decoded);
        }
        return $last;
    }

    /**
     * Reachability + basic stats in one call. Returns:
     *   ['ok' => bool, 'latency_ms' => int, 'version' => ?string,
     *    'models_total' => int, 'models_running' => int, 'error' => ?string]
     */
    public function probe(): array
    {
        $start = microtime(true);
        try {
            $version = $this->version();
            // Latency is one round trip; summing all three calls marks distant (tunnelled) nodes as degraded.
            $latency = (int) round((microtime(true) - $start) * 1000);
            $tags    = $this->tags();
            $ps      = $this->ps();
            return [
                'ok'             => true,
                'latency_ms'     => $latency,
                'version'        => $version['version'] ?? null,
                'models_total'   => count($tags['models'] ?? []),
                'models_running' => count($ps['models'] ?? []),
                'error'          => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'             => false,
                'latency_ms'     => (int) round((microtime(true) - $start) * 1000),
                'version'        => null,
                'models_total'   => 0,
                'models_running' => 0,
                'error'          => $e->getMessage(),
            ];
        }
    }

    /**
     * Low-level JSON request. Throws RuntimeException on transport/HTTP error.
     *
     * @throws \RuntimeException
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $client = $this->client();
        $opts = [
            'http_errors' => false,
            'timeout'     => $this->timeout,
            'connect_timeout' => 5,
            'headers'     => ['Accept' => 'application/json'],
        ];
        if ($this->token) {
            $opts['headers']['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($body !== null) {
            $opts['json'] = $body;
        }

        try {
            $res = $client->request(strtoupper($method), $this->base . $path, $opts);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot reach node: ' . $e->getMessage());
        }

        $code = $res->getStatusCode();
        $raw  = (string) $res->getBody();
        if ($code >= 400) {
            $msg = '';
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['error'])) {
                $msg = is_string($j['error']) ? $j['error'] : json_encode($j['error']);
            }
            throw new \RuntimeException("Node returned HTTP {$code}" . ($msg ? ": {$msg}" : ''));
        }

        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function client(): CURLRequest
    {
        // Fresh client per call; base URL is passed absolute in request().
        return \Config\Services::curlrequest([], null, null, false);
    }
}
