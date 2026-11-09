<?php

namespace App\Controllers\Api;

use App\Services\SpeechService;

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

    public function status()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $j = (new SpeechService())->health(true);
        return $this->ok([
            'available' => (bool) ($j['ok'] ?? false),
            'model'     => $j['model'] ?? null,
            'busy'      => (bool) ($j['busy'] ?? false),
            'max_mb'    => self::MAX_BYTES / 1048576,
            'mixed'     => (bool) ($j['mixed'] ?? false),
            'models'    => $this->visibleModels($j['models'] ?? [['value' => 'sinhala', 'label' => 'Whisper Small · Sinhala', 'note' => '', 'installed' => true]]),
            'run_on'    => array_merge(
                [['value' => 'auto', 'label' => 'Auto — fastest ready node, else this server', 'device' => null, 'ready' => true]],
                $this->nodeChoices(),
                [['value' => 'server', 'label' => 'This server — CPU', 'device' => 'CPU', 'ready' => true]]
            ),
            'languages' => array_values(array_filter([
                ['value' => 'si', 'label' => 'Sinhala'],
                ($j['mixed'] ?? false) ? ['value' => 'mix', 'label' => 'Sinhala + English (mixed)'] : null,
                ['value' => 'en', 'label' => 'English'],
                ['value' => 'auto', 'label' => 'Detect automatically'],
            ])),
        ]);
    }

    /** Speech models the user's role may use (registry access rules), with the registry's default first. */
    private function visibleModels(array $models): array
    {
        $reg  = new \App\Services\ModelRegistryService();
        $role = $this->userRole();
        $out  = array_values(array_filter($models, static fn ($m) => $reg->roleAllowed(SpeechService::registryName((string) ($m['value'] ?? '')), $role)));
        $def  = SpeechService::keyFrom((string) $reg->defaultFor('transcription'));
        usort($out, static fn ($a, $b) => (($b['value'] ?? '') === $def) <=> (($a['value'] ?? '') === $def));
        return $out;
    }

    /** Every remote node, ready or not, with why it cannot take speech yet. */
    private function nodeChoices(): array
    {
        $ready = array_column((new SpeechService())->readyNodes(), null, 'id');
        $rows  = \Config\Database::connect()->table('servers')
            ->select('id, name, is_enabled, asr_enabled, tunnel_port, gpu_model, gpu_count, cpu_cores, agent_version, agent_seen_at')
            ->where('deleted_at', null)->where('is_local', 0)->orderBy('name')->get()->getResultArray();
        $out = [];
        foreach ($rows as $n) {
            $hw = $n['gpu_model'] ? (max(1, (int) $n['gpu_count']) > 1 ? $n['gpu_count'] . ' × ' : '') . trim(explode(',', $n['gpu_model'])[0]) : ($n['cpu_cores'] ? $n['cpu_cores'] . ' CPU cores' : 'CPU');
            $why = match (true) {
                isset($ready[$n['id']])                                        => null,
                ! (int) $n['is_enabled']                                       => 'node disabled',
                ! (int) $n['asr_enabled']                                      => 'speech off for this node',
                empty($n['tunnel_port'])                                       => 'not connected by tunnel',
                ! $n['agent_seen_at'] || $n['agent_seen_at'] < date('Y-m-d H:i:s', time() - 120) => 'training agent not running — install it on the Training page',
                version_compare((string) $n['agent_version'], '1.3.0', '<')    => 'agent ' . $n['agent_version'] . ' — reinstall the agent to add speech',
                default                                                        => 'setting up speech (first time takes a few minutes)',
            };
            $out[] = ['value' => 'node:' . $n['id'], 'label' => $n['name'] . ' — ' . $hw . ($why ? " ({$why})" : ''), 'device' => $ready[$n['id']]['asr_device'] ?? $hw, 'ready' => $why === null];
        }
        return $out;
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
        // Model: the one asked for, else the registry's default for transcription, else Whisper Sinhala.
        $registry = new \App\Services\ModelRegistryService();
        $asked    = (string) ($this->request->getPost('model') ?? '');
        $model    = SpeechService::keyFrom($asked !== '' ? $asked : (string) $registry->defaultFor('transcription')) ?? 'sinhala';
        if (! $registry->roleAllowed(SpeechService::registryName($model), $this->userRole())) {
            return $this->failure("You do not have access to the speech model '{$model}'.", 403);
        }

        // Where to run: a GPU node's speech service (forwarded over its tunnel) or this server's CPU service.
        $svc     = new SpeechService();
        $targets = $svc->targets((string) ($this->request->getPost('run_on') ?? 'auto'));
        if ($targets === null) return $this->failure('That GPU node is not ready for speech right now. Choose Auto or This server.', 409);
        [$code, $j, $used, $err] = $svc->transcribe($file->getTempName(), [
            'language' => $lang, 'model' => $model, 'words' => (bool) $this->request->getPost('words'),
            'fast' => (bool) $this->request->getPost('fast'), 'numerals' => $this->request->getPost('numerals') !== '0',
        ], $targets);
        if ($j === null && $code === 0) {
            log_message('error', 'Speech service unreachable: ' . $err);
            return $this->failure('The speech service is not running. Ask an administrator to start aistudio-asr.', 503);
        }
        $j = $j ?? [];
        if ($code !== 200) {
            return $this->failure($j['error'] ?? 'Transcription failed.', $code >= 400 && $code < 500 ? $code : 502);
        }
        $j['ran_on'] = ['name' => $used['name'], 'device' => $used['device']];
        return $this->ok($j, $j['text'] === '' ? 'No speech was found in this audio.' : 'Transcribed.');
    }
}
