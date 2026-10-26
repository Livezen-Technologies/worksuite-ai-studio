<?php

namespace App\Controllers\Api;

/**
 * Speech to text — record or upload audio and get a Sinhala (or English) transcript.
 *
 *   GET  /api/speech/status        is the speech service up, and which model it runs
 *   POST /api/speech/transcribe    multipart `audio` (≤ 500 MB: WAV / MP3 / M4A / OGG / WebM / FLAC, or MP4 / MOV / WebM video),
 *                                  `language` = si | en | mix | auto, `words` = 1 for per-word timings (captions),
 *                                  `fast` = 1 for short live chunks  →  {text, segments[{start,end,text,words?}], language, duration, …}
 *
 * The work is done by the local aistudio-asr service (Whisper Sinhala on faster-whisper, 127.0.0.1:8096,
 * deploy/aistudio-asr.service). Audio is passed through and not stored.
 */
class SpeechController extends BaseApiController
{
    private const MAX_BYTES = 500 * 1024 * 1024; // video uploads for captions

    private function serviceUrl(): string
    {
        return rtrim((string) (env('ASR_SERVICE_URL') ?: 'http://127.0.0.1:8096'), '/');
    }

    public function status()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $ch = curl_init($this->serviceUrl() . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = $code === 200 ? json_decode((string) $body, true) : null;
        return $this->ok([
            'available' => (bool) ($j['ok'] ?? false),
            'model'     => $j['model'] ?? null,
            'busy'      => (bool) ($j['busy'] ?? false),
            'max_mb'    => self::MAX_BYTES / 1048576,
            'mixed'     => (bool) ($j['mixed'] ?? false),
            'models'    => $j['models'] ?? [['value' => 'sinhala', 'label' => 'Whisper Small · Sinhala', 'note' => '', 'installed' => true]],
            'run_on'    => array_merge(
                [['value' => 'auto', 'label' => 'Auto — a free GPU node, else this server', 'device' => null]],
                array_map(fn ($n) => ['value' => 'node:' . $n['id'], 'label' => $n['name'] . ' — GPU', 'device' => $n['asr_device']], $this->gpuNodes()),
                [['value' => 'server', 'label' => 'This server — CPU', 'device' => 'CPU']]
            ),
            'languages' => array_values(array_filter([
                ['value' => 'si', 'label' => 'Sinhala'],
                ($j['mixed'] ?? false) ? ['value' => 'mix', 'label' => 'Sinhala + English (mixed)'] : null,
                ['value' => 'en', 'label' => 'English'],
                ['value' => 'auto', 'label' => 'Detect automatically'],
            ])),
        ]);
    }

    /** GPU nodes whose speech service reported healthy in the last 90 s (enabled, tunnel port known). */
    private function gpuNodes(): array
    {
        return \Config\Database::connect()->table('servers')->select('id, name, asr_port, asr_device, asr_seen_at')
            ->where('deleted_at', null)->where('is_enabled', 1)->where('asr_enabled', 1)->where('asr_port IS NOT NULL')
            ->where('asr_seen_at >=', date('Y-m-d H:i:s', time() - 90))->orderBy('id')->get()->getResultArray();
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

    public function transcribe()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $file = $this->request->getFile('audio');
        if (! $file || ! $file->isValid()) {
            return $this->failure('Choose or record an audio file first.', 422, ['audio' => 'No audio received.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return $this->failure('Audio and video files can be up to 500 MB.', 413, ['audio' => 'File too large.']);
        }
        $lang = strtolower((string) ($this->request->getPost('language') ?? 'si'));
        if (! in_array($lang, ['si', 'en', 'mix', 'auto'], true)) $lang = 'si';
        $model = strtolower((string) ($this->request->getPost('model') ?? 'sinhala'));
        if (! preg_match('/^[a-z0-9_-]{1,30}$/', $model)) $model = 'sinhala';   // keys like sinhala_numbers
        $query = http_build_query(['language' => $lang, 'model' => $model, 'words' => $this->request->getPost('words') ? 1 : 0, 'fast' => $this->request->getPost('fast') ? 1 : 0, 'numerals' => $this->request->getPost('numerals') === '0' ? 0 : 1]);

        // Where to run: a GPU node's speech service (forwarded over its tunnel) or this server's CPU service.
        $runOn   = (string) ($this->request->getPost('run_on') ?? 'auto');
        $targets = [];
        if ($runOn !== 'server') {
            foreach ($this->gpuNodes() as $n) {
                if ($runOn === 'auto' || $runOn === 'node:' . $n['id']) $targets[] = ['url' => 'http://127.0.0.1:' . (int) $n['asr_port'], 'name' => $n['name'], 'device' => $n['asr_device'] ?: 'GPU'];
            }
            if ($runOn !== 'auto' && ! $targets) return $this->failure('That GPU node is not ready for speech right now. Choose Auto or This server.', 409);
        }
        if ($runOn === 'auto' || $runOn === 'server') $targets[] = ['url' => $this->serviceUrl(), 'name' => 'AI Studio server', 'device' => 'CPU'];

        $code = 0; $body = false; $err = ''; $used = null;
        foreach ($targets as $t) {
            [$code, $body, $err] = $this->forward($t['url'], $query, $file->getTempName());
            $used = $t;
            // a node that is unreachable, busy failing, or lacks this model hands over to the next target
            if ($body !== false && $code !== 0 && $code !== 503 && $code < 500) break;
            log_message('warning', "Speech on {$t['name']} failed ({$code} {$err}); trying the next target.");
        }
        if ($body === false || $code === 0) {
            log_message('error', 'Speech service unreachable: ' . $err);
            return $this->failure('The speech service is not running. Ask an administrator to start aistudio-asr.', 503);
        }
        $j = json_decode((string) $body, true) ?: [];
        if ($code !== 200) {
            return $this->failure($j['error'] ?? 'Transcription failed.', $code >= 400 && $code < 500 ? $code : 502);
        }
        $j['ran_on'] = ['name' => $used['name'], 'device' => $used['device']];
        return $this->ok($j, $j['text'] === '' ? 'No speech was found in this audio.' : 'Transcribed.');
    }
}
