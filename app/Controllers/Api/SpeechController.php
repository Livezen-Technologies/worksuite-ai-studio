<?php

namespace App\Controllers\Api;

/**
 * Speech to text — record or upload audio and get a Sinhala (or English) transcript.
 *
 *   GET  /api/speech/status        is the speech service up, and which model it runs
 *   POST /api/speech/transcribe    multipart `audio` (≤ 500 MB: WAV / MP3 / M4A / OGG / WebM / FLAC, or MP4 / MOV / WebM video),
 *                                  `language` = si | en | auto, `words` = 1 for per-word timings (captions),
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
            'languages' => [['value' => 'si', 'label' => 'Sinhala'], ['value' => 'en', 'label' => 'English'], ['value' => 'auto', 'label' => 'Detect automatically']],
        ]);
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
        if (! in_array($lang, ['si', 'en', 'auto'], true)) $lang = 'si';
        $query = http_build_query(['language' => $lang, 'words' => $this->request->getPost('words') ? 1 : 0, 'fast' => $this->request->getPost('fast') ? 1 : 0]);

        // Stream the upload from disk: a 60 MB recording must not be read into PHP's memory (memory_limit 128M).
        $path = $file->getTempName();
        $fh   = fopen($path, 'rb');
        $ch   = curl_init($this->serviceUrl() . '/transcribe?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_UPLOAD         => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => filesize($path),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/octet-stream', 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 900,          // long recordings on CPU
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($body === false || $code === 0) {
            log_message('error', 'Speech service unreachable: ' . $err);
            return $this->failure('The speech service is not running. Ask an administrator to start aistudio-asr.', 503);
        }
        $j = json_decode((string) $body, true) ?: [];
        if ($code !== 200) {
            return $this->failure($j['error'] ?? 'Transcription failed.', $code >= 400 && $code < 500 ? $code : 502);
        }
        return $this->ok($j, $j['text'] === '' ? 'No speech was found in this audio.' : 'Transcribed.');
    }
}
