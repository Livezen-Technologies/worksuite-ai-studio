<?php

namespace App\Services;

/**
 * TtsService — the text-to-speech service (aistudio-tts, deploy/tts/tts_service.py, 127.0.0.1:8098).
 * Shared by the Speech page and the OpenAI-compatible gateway (/audio/speech).
 *
 * Only approved voices speak; the service enforces that, and the registry adds access by role.
 * Voices are named `tts:<key>` in the registry.
 */
class TtsService
{
    public const FORMATS = ['mp3', 'opus', 'aac', 'flac', 'wav', 'pcm'];

    public static function serviceUrl(): string
    {
        return rtrim((string) (env('TTS_SERVICE_URL') ?: 'http://127.0.0.1:8098'), '/');
    }

    /** /health (cached 30 s); null when the service does not answer. */
    public function health(bool $fresh = false): ?array
    {
        if (! $fresh && is_array($hit = cache('tts_health'))) return $hit['ok'] ? $hit['body'] : null;
        [$code, $body] = $this->call('GET', '/health', null, 5);
        $j = $code === 200 ? json_decode((string) $body, true) : null;
        cache()->save('tts_health', ['ok' => is_array($j), 'body' => $j], 30);
        return is_array($j) ? $j : null;
    }

    /** key => voice metadata (label, language, licence, consent, speed range, approved, revoked, installed). */
    public function voices(): array
    {
        $out = [];
        foreach ((array) ($this->health()['voices'] ?? []) as $v) {
            if (! empty($v['value'])) $out[(string) $v['value']] = $v;
        }
        return $out;
    }

    /** Voices that can speak now: approved, not withdrawn, installed. */
    public function usable(): array
    {
        return array_filter($this->voices(), static fn ($v) => ! empty($v['approved']) && empty($v['revoked']) && ! empty($v['installed']));
    }

    /** Preview of what the voice will read (numbers, dates, currency expanded). */
    public function normalize(string $text, string $voice): array
    {
        [$code, $body] = $this->call('POST', '/normalize', ['text' => $text, 'voice' => $voice], 15);
        $j = json_decode((string) $body, true) ?: [];
        if ($code !== 200) throw new \RuntimeException($j['error'] ?? 'The text-to-speech service is not answering.');
        return $j;
    }

    /**
     * Synthesize. Returns [httpCode, audioBytes|null, meta{content_type, duration, speed, normalized, notes}, error|null].
     */
    public function synthesize(string $text, string $voice, float $speed = 1.0, string $format = 'mp3'): array
    {
        [$code, $body, $headers] = $this->call('POST', '/synthesize', ['text' => $text, 'voice' => $voice, 'speed' => $speed, 'format' => $format], 180);
        if ($code !== 200) {
            $j = json_decode((string) $body, true) ?: [];
            return [$code ?: 503, null, [], $j['error'] ?? ($code ? 'Synthesis failed.' : 'The text-to-speech service is not running.')];
        }
        $meta = [
            'content_type' => $headers['content-type'] ?? 'application/octet-stream',
            'duration' => (float) ($headers['x-duration'] ?? 0), 'speed' => (float) ($headers['x-speed'] ?? $speed),
            'normalized' => json_decode($headers['x-normalized-text'] ?? '""', true), 'notes' => json_decode($headers['x-notes'] ?? '[]', true) ?: [],
        ];
        return [200, (string) $body, $meta, null];
    }

    /** @return array{0:int,1:string|false,2:array<string,string>} */
    private function call(string $method, string $path, ?array $json, int $timeout): array
    {
        $headers = [];
        $ch = curl_init(self::serviceUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            },
        ] + ($json !== null ? [CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE)] : []));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body, $headers];
    }

    public static function registryName(string $key): string
    {
        return 'tts:' . $key;
    }

    /** Voice key from `tts:<key>`, a bare key, or null for anything else. */
    public static function keyFrom(string $name): ?string
    {
        $n = strtolower(trim($name));
        if (str_starts_with($n, 'tts:')) $n = substr($n, 4);
        return preg_match('/^[a-z0-9_-]{1,40}$/', $n) ? $n : null;
    }
}
