<?php

namespace App\Services;

/**
 * SpeechService — where speech-to-text runs and how audio reaches it. Shared by the Speech page
 * (SpeechController) and the OpenAI-compatible gateway (/audio/transcriptions).
 *
 * Audio goes to a GPU node's speech service over its tunnel when one is ready, else to this server's
 * aistudio-asr service (Whisper on faster-whisper, deploy/aistudio-asr.service). Audio is streamed
 * through and never stored.
 */
class SpeechService
{
    /** Models the speech service always ships (deploy/asr/asr_service.py CATALOG). */
    public const BUILT_IN = [
        'sinhala' => ['Whisper Small · Sinhala', 'Fine-tuned for Sinhala. The default.', 'si', 0.5],
        'tiny'    => ['Whisper Tiny', 'Fastest, lower accuracy. Multilingual.', null, 0.1],
        'base'    => ['Whisper Base', 'Fast; also detects the language in mixed speech. Multilingual.', null, 0.2],
        'small'   => ['Whisper Small', 'Good balance of speed and accuracy. Multilingual.', null, 0.5],
        'medium'  => ['Whisper Medium', 'Higher accuracy, slower. Multilingual.', null, 1.5],
        'turbo'   => ['Whisper Large v3 Turbo', 'Best multilingual accuracy; slow on CPU.', null, 1.6],
    ];

    public static function serviceUrl(): string
    {
        return rtrim((string) (env('ASR_SERVICE_URL') ?: 'http://127.0.0.1:8096'), '/');
    }

    /** /health of this server's speech service (cached 30 s); null when it does not answer. */
    public function health(bool $fresh = false): ?array
    {
        $cache = cache();
        if (! $fresh && is_array($hit = $cache->get('speech_health'))) return $hit['ok'] ? $hit['body'] : null;
        $ch = curl_init(self::serviceUrl() . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = $code === 200 ? json_decode((string) $body, true) : null;
        $cache->save('speech_health', ['ok' => is_array($j), 'body' => $j], 30);
        return is_array($j) ? $j : null;
    }

    /**
     * Every speech model: key => {label, note, installed, language, custom}. The live service list wins;
     * built-ins and the custom catalog fill in when the service is down.
     */
    public function models(): array
    {
        $out = [];
        foreach (self::BUILT_IN as $k => [$label, $note, $lang]) $out[$k] = ['label' => $label, 'note' => $note, 'installed' => null, 'language' => $lang, 'custom' => false];
        foreach (SpeechModelService::catalog() as $k => $v) {
            $out[$k] = ['label' => $v['label'] ?? $k, 'note' => $v['note'] ?? 'Custom speech model.', 'installed' => null, 'language' => $v['language'] ?? null, 'custom' => true];
        }
        $health = $this->health();
        if ($health !== null) foreach ($out as &$o) $o['installed'] = false; // the live service is the truth when it answers
        unset($o);
        foreach ((array) ($health['models'] ?? []) as $m) {
            $k = (string) ($m['value'] ?? '');
            if ($k === '') continue;
            $out[$k] = ['label' => $m['label'] ?? $k, 'note' => $m['note'] ?? '', 'installed' => (bool) ($m['installed'] ?? false),
                'language' => $out[$k]['language'] ?? null, 'custom' => $out[$k]['custom'] ?? true];
        }
        return $out;
    }

    /** Nodes (GPU first) whose speech service reported healthy in the last 90 s (enabled, tunnel port known). */
    public function readyNodes(): array
    {
        return \Config\Database::connect()->table('servers')->select('id, name, asr_port, asr_device, asr_seen_at')
            ->where('deleted_at', null)->where('is_enabled', 1)->where('asr_enabled', 1)->where('asr_port IS NOT NULL')
            ->where('asr_seen_at >=', date('Y-m-d H:i:s', time() - 90))
            ->orderBy('gpu_model IS NULL', '', false)->orderBy('gpu_count', 'DESC')->orderBy('id')->get()->getResultArray();
    }

    /** Where to try, in order, for run_on = auto | server | node:<id>. Null when a named node is not ready. */
    public function targets(string $runOn = 'auto'): ?array
    {
        $targets = [];
        if ($runOn !== 'server') {
            foreach ($this->readyNodes() as $n) {
                if ($runOn === 'auto' || $runOn === 'node:' . $n['id']) {
                    $targets[] = ['url' => 'http://127.0.0.1:' . (int) $n['asr_port'], 'name' => $n['name'], 'device' => $n['asr_device'] ?: 'GPU', 'server_id' => (int) $n['id']];
                }
            }
            if ($runOn !== 'auto' && ! $targets) return null;
        }
        if ($runOn === 'auto' || $runOn === 'server') $targets[] = ['url' => self::serviceUrl(), 'name' => 'AI Studio server', 'device' => 'CPU', 'server_id' => null];
        return $targets;
    }

    /**
     * Transcribe a file, trying each target until one answers. $opts: language (si|en|mix|auto), model (key),
     * words, fast, numerals. Returns [httpCode, decodedBody|null, target|null, transportError].
     */
    public function transcribe(string $path, array $opts, array $targets): array
    {
        $query = http_build_query([
            'language' => $opts['language'] ?? 'si', 'model' => $opts['model'] ?? 'sinhala', 'words' => ! empty($opts['words']) ? 1 : 0,
            'fast' => ! empty($opts['fast']) ? 1 : 0, 'numerals' => ($opts['numerals'] ?? true) ? 1 : 0,
            'task' => ($opts['task'] ?? 'transcribe') === 'translate' ? 'translate' : 'transcribe',
        ]);
        $code = 0; $body = false; $err = ''; $used = null;
        foreach ($targets as $t) {
            [$code, $body, $err] = $this->forward($t['url'], $query, $path);
            $used = $t;
            // a node that is unreachable, failing, or lacks this model hands over to the next target
            if ($body !== false && $code !== 0 && $code !== 503 && $code < 500) break;
            log_message('warning', "Speech on {$t['name']} failed ({$code} {$err}); trying the next target.");
        }
        $j = $body !== false ? json_decode((string) $body, true) : null;
        return [$code, is_array($j) ? $j : null, $used, $err];
    }

    /** Stream the upload to a speech service (never read into PHP memory). Returns [http code, body|false, curl error]. */
    private function forward(string $base, string $query, string $path): array
    {
        $fh = fopen($path, 'rb');
        $ch = curl_init(rtrim($base, '/') . '/transcribe?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => filesize($path), CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        return [$code, $body, $err];
    }

    /** Registry name for a speech model key. */
    public static function registryName(string $key): string
    {
        return 'whisper:' . $key;
    }

    /** Speech model key from a registry name, an OpenAI-style name, or a bare key; null when it is not a speech model name. */
    public static function keyFrom(string $name): ?string
    {
        $n = strtolower(trim($name));
        if (str_starts_with($n, 'whisper:')) $n = substr($n, 8);
        return preg_match('/^[a-z0-9_-]{1,30}$/', $n) ? $n : null;
    }
}
