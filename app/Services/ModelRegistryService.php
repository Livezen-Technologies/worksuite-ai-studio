<?php

namespace App\Services;

/**
 * ModelRegistryService — the central AI Model Registry.
 *
 * A registry entry is a logical model (type, version, capabilities, VRAM need, access roles,
 * load policy). Assignments place it on nodes; each assignment has a deployment state. The
 * per-node `models` table remains the record of what is actually installed, so deployment
 * status is derived from both: an installed copy is "deployed" (or "loaded" when resident).
 */
class ModelRegistryService
{
    public const TYPES        = ['llm', 'embedding', 'stt', 'tts', 'vision', 'multimodal'];
    public const CAPABILITIES = ['chat', 'completion', 'embeddings', 'tools', 'json', 'vision', 'audio_in', 'audio_out', 'code', 'reasoning'];
    public const TASKS        = ['chat', 'generate', 'embeddings', 'translate', 'summarize', 'vision', 'code', 'transcription', 'speech'];

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // ---- Discovery & inference ------------------------------------------------

    /** Best guess of type + capabilities from a model id ("nomic-embed-text", "llava:7b", "whisper-large-v3"). */
    public static function infer(string $name): array
    {
        $n = strtolower($name);
        $type = match (true) {
            (bool) preg_match('/embed|bge|e5-|minilm|gte-/', $n)                => 'embedding',
            (bool) preg_match('/whisper|asr|speech-to-text|stt/', $n)           => 'stt',
            (bool) preg_match('/tts|piper|bark|xtts|kokoro/', $n)               => 'tts',
            (bool) preg_match('/llava|vision|moondream|minicpm-v|bakllava/', $n) => 'vision',
            (bool) preg_match('/qwen2\.5-omni|omni|gemma3/', $n)                => 'multimodal',
            default                                                            => 'llm',
        };
        $caps = match ($type) {
            'embedding'  => ['embeddings'],
            'stt'        => ['audio_in'],
            'tts'        => ['audio_out'],
            'vision'     => ['chat', 'completion', 'vision'],
            'multimodal' => ['chat', 'completion', 'vision', 'audio_in'],
            default      => ['chat', 'completion'],
        };
        if ($type === 'llm') {
            if (preg_match('/coder|code|starcoder/', $n)) $caps[] = 'code';
            if (preg_match('/deepseek-r1|qwq|reason|o1|o3/', $n)) $caps[] = 'reasoning';
            if (preg_match('/llama3\.[1-3]|qwen2\.5|qwen3|mistral|command-r|hermes/', $n)) $caps[] = 'tools';
        }
        return ['type' => $type, 'capabilities' => $caps];
    }

    /** VRAM a model needs: weights plus ~20 % for KV cache and runtime, from its file size. */
    public static function vramFromSize(?int $bytes): ?float
    {
        return $bytes ? round($bytes / 1073741824 * 1.2 + 0.5, 1) : null;
    }

    /**
     * Create registry entries for every installed model that has none yet. Returns how many
     * were added. Existing entries are left as the admin configured them.
     */
    public function discover(?int $userId = null): int
    {
        $speech = $this->syncSpeech($userId) + $this->syncVoices($userId);
        $known = array_flip(array_column($this->db->table('model_registry')->select('name')->get()->getResultArray(), 'name'));
        $rows  = $this->db->table('models m')
            ->select('m.name, MAX(m.family) AS family, MAX(m.parameter_size) AS parameter_size, MAX(m.quantization) AS quantization, MAX(m.context_length) AS context_length, MAX(m.size_bytes) AS size_bytes, MAX(s.kind) AS kind')
            ->join('servers s', 's.id = m.server_id')->where('s.deleted_at', null)
            ->groupBy('m.name')->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        $added = 0;
        foreach ($rows as $r) {
            if (isset($known[$r['name']])) continue;
            $guess = self::infer($r['name']);
            $this->db->table('model_registry')->insert([
                'name' => $r['name'], 'type' => $guess['type'], 'capabilities' => json_encode($guess['capabilities']),
                'family' => $r['family'], 'parameter_size' => $r['parameter_size'], 'quantization' => $r['quantization'],
                'context_length' => $r['context_length'], 'vram_gb' => self::vramFromSize((int) $r['size_bytes'] ?: null),
                'version' => str_contains($r['name'], ':') ? substr($r['name'], strpos($r['name'], ':') + 1) : 'latest',
                'engine' => in_array($r['kind'], ProviderService::EXTERNAL, true) ? 'cloud' : ($r['kind'] ?: 'ollama'),
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $added++;
        }
        // Copies already on nodes count as deployed assignments.
        $this->db->query('INSERT INTO ' . $this->db->prefixTable('model_assignments') . ' (registry_id, server_id, state, created_at, updated_at)
            SELECT r.id, m.server_id, \'deployed\', ?, ? FROM ' . $this->db->prefixTable('model_registry') . ' r
            JOIN ' . $this->db->prefixTable('models') . ' m ON m.name = r.name
            JOIN ' . $this->db->prefixTable('servers') . ' s ON s.id = m.server_id AND s.deleted_at IS NULL
            WHERE NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('model_assignments') . ' a WHERE a.registry_id = r.id AND a.server_id = m.server_id)', [$now, $now]);
        return $added + $speech;
    }

    /**
     * Register every speech-to-text model the speech service offers (built-in Whisper sizes and models
     * trained in the Studio) as `whisper:<key>`, engine "speech". Returns how many were added.
     */
    public function syncSpeech(?int $userId = null): int
    {
        $now = date('Y-m-d H:i:s');
        $added = 0;
        foreach ((new SpeechService())->models() as $key => $m) {
            $name = SpeechService::registryName($key);
            if ($this->findByName($name)) continue;
            $cat = \App\Libraries\ModelCatalogue::find($name);
            $this->db->table('model_registry')->insert([
                'name' => $name, 'display_name' => $m['label'], 'type' => 'stt', 'engine' => 'speech', 'family' => 'whisper',
                'capabilities' => json_encode(['audio_in']), 'description' => mb_substr((string) $m['note'], 0, 255) ?: null,
                'parameter_size' => $cat['parameter_size'] ?? null, 'quantization' => $cat['quantization'] ?? 'INT8', 'vram_gb' => $cat['vram_gb'] ?? null,
                'version' => $m['custom'] ? 'custom' : 'built-in', 'source' => $cat['source'] ?? null,
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $added++;
        }
        return $added;
    }

    /**
     * Register every text-to-speech voice the TTS service offers as `tts:<key>` (engine "tts"). A voice the
     * service lists as not approved or withdrawn is registered disabled. Returns how many were added.
     */
    public function syncVoices(?int $userId = null): int
    {
        $now = date('Y-m-d H:i:s');
        $added = 0;
        foreach ((new TtsService())->voices() as $key => $v) {
            $name = TtsService::registryName($key);
            $usable = ! empty($v['approved']) && empty($v['revoked']);
            if ($row = $this->findByName($name)) {
                if (! $usable && (int) $row['enabled'] === 1) $this->db->table('model_registry')->where('id', $row['id'])->update(['enabled' => 0, 'updated_at' => $now]); // withdrawn: blocked
                continue;
            }
            $this->db->table('model_registry')->insert([
                'name' => $name, 'display_name' => $v['label'] ?? $key, 'type' => 'tts', 'engine' => 'tts', 'family' => (string) ($v['runtime'] ?? ''),
                'capabilities' => json_encode(['audio_out']), 'enabled' => $usable ? 1 : 0,
                'description' => mb_substr(trim(($v['quality'] ?? '') . ' Licence: ' . ($v['license'] ?? 'unknown') . '. Consent: ' . ($v['consent_ref'] ?? 'unknown') . '.'), 0, 255),
                'version' => $v['language'] ?? null, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $added++;
        }
        return $added;
    }

    /** Where voices run: the text-to-speech service on this server. */
    private function voiceDeployments(): array
    {
        $tts = new TtsService();
        $up  = $tts->health() !== null;
        $out = [];
        foreach ($tts->voices() as $key => $v) {
            $status = ! $up || ! empty($v['revoked']) ? 'failed' : (empty($v['installed']) || empty($v['approved']) ? 'pending' : 'deployed');
            $error  = ! $up ? 'The text-to-speech service is not answering.' : (! empty($v['revoked']) ? 'Withdrawn: blocked for new audio.'
                : (empty($v['approved']) ? 'Not approved (licence or consent not verified).' : (empty($v['installed']) ? 'Voice files are not on this server.' : null)));
            $out[TtsService::registryName($key)] = [[
                'server_id' => 0, 'server' => 'AI Studio server', 'kind' => 'tts', 'server_status' => $up ? 'online' : 'offline',
                'status' => $status, 'error' => $error, 'updated_at' => null, 'size_bytes' => null,
            ]];
        }
        return $out;
    }

    /** Where speech models run: this server's speech service, and every node whose speech service is up. */
    private function speechDeployments(): array
    {
        $svc    = new SpeechService();
        $models = $svc->models();
        $up     = $svc->health() !== null;
        $nodes  = $svc->readyNodes();
        $out = [];
        foreach ($models as $key => $m) {
            $deps = [[
                'server_id' => 0, 'server' => 'AI Studio server', 'kind' => 'speech', 'server_status' => $up ? 'online' : 'offline',
                'status' => $m['installed'] === false ? 'pending' : ($up ? 'deployed' : 'failed'),
                'error' => $up ? ($m['installed'] === false ? 'Model files are not on this server yet.' : null) : 'The speech service is not answering.',
                'updated_at' => null, 'size_bytes' => null,
            ]];
            foreach ($nodes as $n) { // nodes receive every model with their speech sync
                $deps[] = ['server_id' => (int) $n['id'], 'server' => $n['name'], 'kind' => 'speech', 'server_status' => 'online', 'status' => 'deployed',
                    'error' => null, 'updated_at' => $n['asr_seen_at'], 'size_bytes' => null];
            }
            $out[SpeechService::registryName($key)] = $deps;
        }
        return $out;
    }

    /**
     * The curated catalogue with what is already here: `registered` (in the registry under one of its
     * names) and `installed_on` (nodes that have it).
     */
    public function catalogue(): array
    {
        $registered = array_flip(array_column($this->db->table('model_registry')->select('name')->get()->getResultArray(), 'name'));
        $installed  = [];
        foreach ($this->db->table('models m')->select('m.name, s.name AS server')->join('servers s', 's.id = m.server_id')->where('s.deleted_at', null)->get()->getResultArray() as $r) {
            $installed[$r['name']][] = $r['server'];
        }
        $speech = (new SpeechService())->models();
        return array_map(static function ($c) use ($registered, $installed, $speech) {
            $names = array_values(array_filter([$c['id'], $c['ollama'], $c['hf']]));
            $c['registered']   = (bool) array_filter($names, static fn ($n) => isset($registered[$n]));
            $c['installed_on'] = array_values(array_unique(array_merge(...array_map(static fn ($n) => $installed[$n] ?? [], $names))));
            if ($c['speech']) $c['installed_on'] = ($speech[$c['speech']]['installed'] ?? null) === false ? [] : ['Speech service'];
            $c['engines'] = array_values(array_filter([$c['ollama'] ? 'ollama' : null, $c['hf'] && ! $c['speech'] ? 'vllm' : null, $c['speech'] ? 'speech' : null]));
            return $c;
        }, \App\Libraries\ModelCatalogue::all());
    }

    /** Hugging Face pipeline tag per registry type, for hub searches. */
    private const HUB_PIPELINES = [
        'llm' => 'text-generation', 'embedding' => 'sentence-similarity', 'stt' => 'automatic-speech-recognition',
        'tts' => 'text-to-speech', 'vision' => 'image-text-to-text', 'multimodal' => 'any-to-any',
    ];

    /**
     * Live search of the Hugging Face model hub (cached 10 minutes). Each result says how each engine
     * can run it: vLLM by repo id; Ollama by `hf.co/<repo>` when the repo ships GGUF files.
     *
     * @throws \RuntimeException when the hub cannot be reached
     */
    public function searchHub(string $q, string $type = 'llm'): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if ($q === '') return [];
        $type = isset(self::HUB_PIPELINES[$type]) ? $type : 'llm';
        $ck = 'hub_' . md5($type . '|' . mb_strtolower($q));
        if (is_array($hit = cache($ck))) return $hit;
        $base = rtrim((string) (env('HF_API_BASE') ?: 'https://huggingface.co'), '/');
        $url  = $base . '/api/models?' . http_build_query(['search' => $q, 'filter' => self::HUB_PIPELINES[$type], 'sort' => 'downloads', 'direction' => -1, 'limit' => 24]);
        $ch = curl_init($url);
        curl_setopt_array($ch, UrlGuard::curlOpts($url) + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: WorkSuite-AI-Studio']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $rows = $code === 200 ? json_decode((string) $body, true) : null;
        if (! is_array($rows)) throw new \RuntimeException('The Hugging Face hub could not be reached' . ($code ? " (HTTP {$code})" : '') . '.');
        $registered = array_flip(array_column($this->db->table('model_registry')->select('name')->get()->getResultArray(), 'name'));
        $out = [];
        foreach ($rows as $m) {
            $id = (string) ($m['id'] ?? $m['modelId'] ?? '');
            if ($id === '' || ! preg_match('#^[\w.-]+/[\w.-]+$#', $id)) continue;
            $tags = array_map('strval', (array) ($m['tags'] ?? []));
            $gguf = in_array('gguf', $tags, true);
            $license = null;
            foreach ($tags as $t) if (str_starts_with($t, 'license:')) { $license = substr($t, 8); break; }
            $engines = $type === 'stt' || $type === 'tts' ? [] : array_values(array_filter([$gguf ? 'ollama' : null, ! $gguf ? 'vllm' : null]));
            $out[] = [
                'id' => $id, 'name' => $id, 'type' => $type, 'downloads' => (int) ($m['downloads'] ?? 0), 'likes' => (int) ($m['likes'] ?? 0),
                'license' => $license, 'gated' => ! empty($m['gated']), 'gguf' => $gguf, 'engines' => $engines,
                'ollama' => $gguf ? 'hf.co/' . $id : null, 'hf' => $gguf ? null : $id,
                'source' => 'https://huggingface.co/' . $id, 'updated_at' => $m['lastModified'] ?? null,
                'registered' => isset($registered[$id]) || isset($registered['hf.co/' . $id]),
            ];
        }
        cache()->save($ck, $out, 600);
        return $out;
    }

    /** Register a hub search result for an engine (vllm → repo id; ollama → hf.co/<repo>, GGUF repos only). */
    public function addFromHub(string $repo, string $type, string $engine, ?int $userId = null): array
    {
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) throw new \InvalidArgumentException('That is not a Hugging Face repository id.');
        $name = $engine === 'ollama' ? 'hf.co/' . $repo : $repo;
        if ($row = $this->findByName($name)) return $row;
        $guess = self::infer($repo);
        $now = date('Y-m-d H:i:s');
        $this->db->table('model_registry')->insert([
            'name' => $name, 'display_name' => $repo, 'type' => in_array($type, self::TYPES, true) ? $type : $guess['type'],
            'capabilities' => json_encode($guess['capabilities']), 'engine' => $engine === 'ollama' ? 'ollama' : 'vllm',
            'source' => 'https://huggingface.co/' . $repo, 'version' => 'latest', 'description' => 'From the Hugging Face hub.',
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->findByName($name);
    }

    /**
     * Register a catalogue model for an engine (ollama → its Ollama tag; vllm / llamacpp → its Hugging
     * Face id; speech → whisper:<key>) and return the registry row. Existing entries are returned as-is.
     */
    public function addFromCatalogue(string $id, string $engine, ?int $userId = null): array
    {
        $c = \App\Libraries\ModelCatalogue::find($id);
        if (! $c) throw new \InvalidArgumentException('That model is not in the catalogue.');
        if ($c['speech']) $engine = 'speech';
        $name = match ($engine) {
            'ollama' => $c['ollama'],
            'vllm', 'llamacpp', 'openai_compatible' => $c['hf'],
            'speech' => $c['id'],
            default  => null,
        };
        if (! $name) throw new \InvalidArgumentException("{$c['name']} is not available for that engine.");
        if ($row = $this->findByName($name)) return $row;
        $now = date('Y-m-d H:i:s');
        $this->db->table('model_registry')->insert([
            'name' => $name, 'display_name' => $c['name'], 'type' => $c['type'], 'family' => $c['family'], 'parameter_size' => $c['parameter_size'],
            'quantization' => $engine === 'ollama' || $engine === 'speech' ? $c['quantization'] : null, 'vram_gb' => $c['vram_gb'],
            'context_length' => $c['context_length'], 'capabilities' => json_encode($c['capabilities']), 'engine' => $engine,
            'source' => $c['source'], 'description' => mb_substr($c['description'] . ' Licence: ' . $c['license'] . '.', 0, 255),
            'version' => str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : 'latest',
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->findByName($name);
    }

    // ---- Read ------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return $this->db->table('model_registry')->where('id', $id)->get()->getRowArray() ?: null;
    }

    public function findByName(string $name): ?array
    {
        return $this->db->table('model_registry')->where('name', $name)->get()->getRowArray() ?: null;
    }

    /** Every entry with its deployments and 24-hour performance. */
    public function all(): array
    {
        $rows = $this->db->table('model_registry')->orderBy('type')->orderBy('name')->get()->getResultArray();
        if (! $rows) return [];
        $deps   = $this->deployments(array_column($rows, 'id'));
        $perf   = $this->performance(array_column($rows, 'name'));
        $speech = in_array('speech', array_column($rows, 'engine'), true) ? $this->speechDeployments() : [];
        if (in_array('tts', array_column($rows, 'engine'), true)) $speech += $this->voiceDeployments();
        return array_map(function ($r) use ($deps, $perf, $speech) {
            $r = $this->publicRow($r);
            $r['deployments'] = in_array($r['engine'], ['speech', 'tts'], true) ? ($speech[$r['name']] ?? []) : ($deps[$r['id']] ?? []);
            $r['performance'] = $perf[$r['name']] ?? null;
            $states = array_column($r['deployments'], 'status');
            $r['status'] = match (true) {
                ! $r['enabled']                         => 'disabled',
                in_array('loaded', $states, true)       => 'loaded',
                in_array('deployed', $states, true)     => 'deployed',
                in_array('deploying', $states, true)    => 'deploying',
                in_array('failed', $states, true)       => 'failed',
                (bool) $states                          => 'pending',
                default                                 => 'registered',
            };
            return $r;
        }, $rows);
    }

    public function publicRow(array $r): array
    {
        $r['capabilities'] = $r['capabilities'] ? (json_decode($r['capabilities'], true) ?: []) : [];
        $r['access_roles'] = $r['access_roles'] ? (json_decode($r['access_roles'], true) ?: null) : null;
        $r['keep_loaded']  = (int) $r['keep_loaded'] === 1;
        $r['enabled']      = (int) $r['enabled'] === 1;
        foreach (['vram_gb'] as $f) $r[$f] = $r[$f] !== null ? (float) $r[$f] : null;
        foreach (['context_length', 'idle_unload_minutes'] as $f) $r[$f] = $r[$f] !== null ? (int) $r[$f] : null;
        return $r;
    }

    /** registry_id => [ {server_id, server, kind, server_status, status, error, size_bytes} ] */
    public function deployments(array $ids): array
    {
        if (! $ids) return [];
        $rows = $this->db->table('model_assignments a')
            ->select('a.registry_id, a.server_id, a.state, a.error, a.updated_at, a.job_id, s.name AS server, s.kind, s.status AS server_status, s.gpu_vram_gb, s.vram_used_gb,
                      m.status AS installed_status, m.size_bytes, q.status AS job_status, q.error AS job_error')
            ->join('model_registry r', 'r.id = a.registry_id')
            ->join('servers s', 's.id = a.server_id')
            ->join('models m', 'm.server_id = a.server_id AND m.name = r.name', 'left')
            ->join('queues q', 'q.id = a.job_id', 'left')
            ->whereIn('a.registry_id', $ids)->where('s.deleted_at', null)
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $d) {
            // A download whose queue job is gone, failed or cancelled is not "deploying" any more.
            if ($d['state'] === 'deploying' && $d['installed_status'] === null && ($d['job_status'] === null || in_array($d['job_status'], ['failed', 'cancelled'], true))) {
                $d['state'] = 'failed';
                $d['error'] = $d['job_error'] ?: ($d['job_status'] === null ? 'The download job no longer exists.' : 'The download job ' . $d['job_status'] . '.');
            }
            $status = match (true) {
                $d['state'] === 'removing'                                => 'removing',
                $d['installed_status'] === 'running'                      => 'loaded',
                $d['installed_status'] !== null                           => 'deployed',
                in_array($d['state'], ['deploying', 'failed'], true)      => $d['state'],
                default                                                   => 'pending',
            };
            $out[(int) $d['registry_id']][] = [
                'server_id' => (int) $d['server_id'], 'server' => $d['server'], 'kind' => $d['kind'], 'server_status' => $d['server_status'],
                'status' => $status, 'error' => $d['error'], 'updated_at' => $d['updated_at'],
                'size_bytes' => $d['size_bytes'] !== null ? (int) $d['size_bytes'] : null,
            ];
        }
        return $out;
    }

    /** model name => {requests, errors, error_rate, avg_ms, tokens, tokens_per_sec} over $hours. */
    public function performance(array $names, int $hours = 24): array
    {
        if (! $names) return [];
        $rows = $this->db->table('api_logs')
            ->select('model, COUNT(*) AS requests, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors,
                      AVG(CASE WHEN status < 400 THEN processing_ms END) AS avg_ms, COALESCE(SUM(total_tokens), 0) AS tokens,
                      COALESCE(SUM(completion_tokens), 0) AS out_tokens, COALESCE(SUM(CASE WHEN completion_tokens IS NOT NULL THEN processing_ms END), 0) AS gen_ms')
            ->whereIn('model', $names)->where('created_at >=', date('Y-m-d H:i:s', time() - $hours * 3600))
            ->groupBy('model')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $req = (int) $r['requests'];
            $out[$r['model']] = [
                'requests' => $req, 'errors' => (int) $r['errors'],
                'error_rate' => $req ? round((int) $r['errors'] / $req * 100, 1) : 0.0,
                'avg_ms' => $r['avg_ms'] !== null ? (int) round((float) $r['avg_ms']) : null,
                'tokens' => (int) $r['tokens'],
                'tokens_per_sec' => (int) $r['gen_ms'] > 0 ? round((int) $r['out_tokens'] / ((int) $r['gen_ms'] / 1000), 1) : null,
            ];
        }
        return $out;
    }

    // ---- Write -----------------------------------------------------------------

    /** Whitelist + normalise editable fields. */
    public function fields(array $in): array
    {
        $out = [];
        foreach (['display_name', 'version', 'family', 'parameter_size', 'quantization', 'engine', 'source', 'description'] as $f) {
            if (array_key_exists($f, $in)) $out[$f] = ($v = trim((string) $in[$f])) === '' ? null : mb_substr($v, 0, $f === 'source' || $f === 'description' ? 255 : 191);
        }
        if (isset($in['type']) && in_array($in['type'], self::TYPES, true)) $out['type'] = $in['type'];
        foreach (['vram_gb' => 4096, 'context_length' => 10000000, 'idle_unload_minutes' => 100000] as $f => $max) {
            if (array_key_exists($f, $in)) $out[$f] = (is_numeric($in[$f]) && $in[$f] >= 0 && $in[$f] <= $max) ? $in[$f] + 0 : null;
        }
        if (array_key_exists('capabilities', $in) && is_array($in['capabilities'])) {
            $out['capabilities'] = json_encode(array_values(array_intersect(self::CAPABILITIES, $in['capabilities'])));
        }
        if (array_key_exists('access_roles', $in)) {
            $roles = is_array($in['access_roles']) ? array_values(array_filter(array_map('strval', $in['access_roles']))) : [];
            $out['access_roles'] = $roles ? json_encode($roles) : null;
        }
        foreach (['keep_loaded', 'enabled'] as $f) if (array_key_exists($f, $in)) $out[$f] = ! empty($in[$f]) && $in[$f] !== 'false' ? 1 : 0;
        return $out;
    }

    /**
     * Check a node can host the model. Returns a reason it cannot, or null. Cloud APIs cannot
     * be deployed to; a node whose total VRAM is known must have at least the model's need.
     */
    public function placementProblem(array $model, array $server): ?string
    {
        $kind = $server['kind'] ?? 'ollama';
        if (in_array($kind, ProviderService::EXTERNAL, true)) return "{$server['name']} is a hosted API; models cannot be deployed to it.";
        $need  = (float) ($model['vram_gb'] ?? 0);
        $total = (float) ($server['gpu_vram_gb'] ?? 0);
        if ($need > 0 && $total > 0 && $need > $total) {
            return sprintf('%s needs about %.1f GB of VRAM; %s has %.1f GB.', $model['name'], $need, $server['name'], $total);
        }
        return null;
    }

    /**
     * Assign a model to nodes. Ollama nodes get a queued pull; other engines serve a fixed model
     * set, so the assignment is recorded and turns "deployed" once the engine lists the model.
     *
     * @return array{queued:list<string>, recorded:list<string>, skipped:array<string,string>}
     */
    public function deploy(array $model, array $serverIds, bool $force = false): array
    {
        $out = ['queued' => [], 'recorded' => [], 'skipped' => []];
        $now = date('Y-m-d H:i:s');
        foreach (array_unique(array_map('intval', $serverIds)) as $sid) {
            $server = $this->db->table('servers')->where('id', $sid)->where('deleted_at', null)->get()->getRowArray();
            if (! $server) { $out['skipped']["#{$sid}"] = 'Server not found.'; continue; }
            if (($why = $this->placementProblem($model, $server)) && ! ($force && ! in_array($server['kind'] ?? 'ollama', ProviderService::EXTERNAL, true))) {
                $out['skipped'][$server['name']] = $why;
                continue;
            }
            $row = ['registry_id' => $model['id'], 'server_id' => $sid, 'error' => null, 'updated_at' => $now];
            if (($server['kind'] ?? 'ollama') === 'ollama') {
                $row['job_id'] = (new QueueService())->enqueue('pull_model', ['server_id' => $sid, 'name' => $model['name'], 'registry_id' => (int) $model['id']], 3, 2);
                $row['state']  = 'deploying';
                $out['queued'][] = $server['name'];
            } else {
                $row['state'] = 'pending';
                $out['recorded'][] = $server['name'];
            }
            $existing = $this->db->table('model_assignments')->where(['registry_id' => $model['id'], 'server_id' => $sid])->get()->getRowArray();
            $existing ? $this->db->table('model_assignments')->where('id', $existing['id'])->update($row)
                      : $this->db->table('model_assignments')->insert($row + ['created_at' => $now]);
        }
        return $out;
    }

    /** Called by the queue when a pull finishes (or fails) so the assignment reflects it. */
    public function pullFinished(int $registryId, int $serverId, ?string $error = null): void
    {
        $this->db->table('model_assignments')->where(['registry_id' => $registryId, 'server_id' => $serverId])
            ->update(['state' => $error ? 'failed' : 'deployed', 'error' => $error ? mb_substr($error, 0, 255) : null, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** Remove a model from one node (deletes the copy on Ollama nodes) and drop the assignment. */
    public function undeploy(array $model, int $serverId): void
    {
        // A download still queued or running is cancelled, so the node stops fetching a model nobody wants.
        $a = $this->db->table('model_assignments')->where(['registry_id' => $model['id'], 'server_id' => $serverId])->get()->getRowArray();
        if ($a && ! empty($a['job_id'])) {
            $this->db->table('queues')->where('id', $a['job_id'])->whereIn('status', ['pending', 'running'])
                ->update(['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s'), 'error' => 'Deployment cancelled.']);
        }
        $server = $this->db->table('servers')->where('id', $serverId)->get()->getRowArray();
        if ($server && ($server['kind'] ?? 'ollama') === 'ollama') {
            $installed = $this->db->table('models')->where(['server_id' => $serverId, 'name' => $model['name']])->countAllResults();
            if ($installed) {
                (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->delete($model['name']);
                $this->db->table('models')->where(['server_id' => $serverId, 'name' => $model['name']])->delete();
            }
        }
        $this->db->table('model_assignments')->where(['registry_id' => $model['id'], 'server_id' => $serverId])->delete();
    }

    /** Re-pull the model on every Ollama node it is assigned to (picks up a new version of the tag). */
    public function update(array $model): array
    {
        $ids = array_column($this->db->table('model_assignments')->select('server_id')->where('registry_id', $model['id'])->get()->getResultArray(), 'server_id');
        return $this->deploy($model, $ids, true);
    }

    // ---- Access & defaults -------------------------------------------------------

    /** name => registry row for every entry (one query), for checking many models at once. */
    public function index(): array
    {
        return array_column($this->db->table('model_registry')->get()->getResultArray(), null, 'name');
    }

    /** roleAllowed() against a row already loaded (null = not registered). */
    public static function rowAllows(?array $r, ?string $role): bool
    {
        if (! $r) return true;
        if ((int) $r['enabled'] !== 1) return false;
        $roles = $r['access_roles'] ? json_decode($r['access_roles'], true) : null;
        if (! is_array($roles) || ! $roles) return true;
        return $role !== null && (in_array($role, $roles, true) || in_array($role, ['super_admin', 'admin'], true));
    }

    /** May a user with this role use the model? Unregistered models and entries without roles are open. */
    public function roleAllowed(string $modelName, ?string $role): bool
    {
        $r = $this->findByName($modelName);
        if (! $r) return true;
        if ((int) $r['enabled'] !== 1) return false;
        $roles = $r['access_roles'] ? json_decode($r['access_roles'], true) : null;
        if (! is_array($roles) || ! $roles) return true;
        return $role !== null && (in_array($role, $roles, true) || in_array($role, ['super_admin', 'admin'], true));
    }

    /** Role name of a user id (null when unknown). */
    public function roleOf(?int $userId): ?string
    {
        if (! $userId) return null;
        $row = $this->db->table('users u')->select('r.name')->join('roles r', 'r.id = u.role_id')->where('u.id', $userId)->get()->getRowArray();
        return $row['name'] ?? null;
    }

    public function defaults(): array
    {
        return $this->db->table('model_defaults')->orderBy('task')->orderBy('application')->get()->getResultArray();
    }

    /** Default model for a task: the application's own choice first, then the global one. */
    public function defaultFor(string $task, ?string $application = null): ?string
    {
        $rows = $this->db->table('model_defaults')->where('task', $task)->whereIn('application', array_values(array_unique([(string) $application, ''])))
            ->get()->getResultArray();
        usort($rows, static fn ($a, $b) => strcmp($b['application'], $a['application'])); // the application's row first
        return $rows[0]['model_name'] ?? null;
    }

    /** Replace the defaults with $rows = [{task, application?, model_name}]. */
    public function saveDefaults(array $rows): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('model_defaults')->emptyTable();
        $seen = [];
        foreach ($rows as $r) {
            $task = (string) ($r['task'] ?? '');
            $app  = mb_substr(trim((string) ($r['application'] ?? '')), 0, 120);
            $name = trim((string) ($r['model_name'] ?? ''));
            if (! in_array($task, self::TASKS, true) || $name === '' || isset($seen["{$task}|{$app}"])) continue;
            $seen["{$task}|{$app}"] = true;
            $this->db->table('model_defaults')->insert(['task' => $task, 'application' => $app, 'model_name' => mb_substr($name, 0, 191), 'updated_at' => $now]);
        }
        $this->db->transComplete();
        return $this->defaults();
    }

    // ---- Automatic loading / unloading ----------------------------------------------

    /**
     * Scheduler tick: keep "keep loaded" models resident on their online Ollama nodes, and unload
     * models that have been idle longer than their idle_unload_minutes. Returns what it did.
     */
    public function autoload(): array
    {
        $done = ['loaded' => [], 'unloaded' => [], 'errors' => []];
        $rows = $this->db->table('model_registry r')
            ->select('r.name, r.keep_loaded, r.idle_unload_minutes, m.id AS model_id, s.id AS server_id, s.name AS server, s.api_url, s.auth_token')
            ->join('model_assignments a', 'a.registry_id = r.id')
            ->join('servers s', 's.id = a.server_id')
            ->join('models m', 'm.server_id = s.id AND m.name = r.name')
            ->where('r.enabled', 1)->where('s.deleted_at', null)->where('s.is_enabled', 1)->whereIn('s.status', ['online', 'degraded'])
            ->where('s.kind', 'ollama') // load / unload is an Ollama API; other engines manage their own memory
            ->groupStart()->where('r.keep_loaded', 1)->orWhere('r.idle_unload_minutes >', 0)->groupEnd()
            ->get()->getResultArray();
        $loaded = []; // server id => [model names in memory], read from the node itself
        foreach ($rows as $r) {
            $sid    = (int) $r['server_id'];
            $label  = "{$r['name']}@{$r['server']}";
            $client = new OllamaService($r['api_url'], $r['auth_token'] ?? null);
            try {
                if (! isset($loaded[$sid])) {
                    $loaded[$sid] = array_map(static fn ($m) => (string) ($m['name'] ?? $m['model'] ?? ''), $client->ps()['models'] ?? []);
                    // Record what is really in memory, whoever loaded it (a chat, the gateway, this job).
                    $this->db->table('models')->where('server_id', $sid)->whereIn('status', ['running', 'available'])
                        ->update(['status' => 'available']);
                    if ($loaded[$sid]) $this->db->table('models')->where('server_id', $sid)->whereIn('name', $loaded[$sid])->update(['status' => 'running']);
                }
                $inMemory = in_array($r['name'], $loaded[$sid], true);
                if ((int) $r['keep_loaded'] === 1) {
                    if (! $inMemory) {
                        $client->load($r['name']); // keep_alive -1: stays until unloaded
                        $this->db->table('models')->where('id', $r['model_id'])->update(['status' => 'running']);
                        $done['loaded'][] = $label;
                    }
                    continue;
                }
                $idle = (int) $r['idle_unload_minutes'];
                if ($inMemory && $idle > 0 && ! $this->usedSince($r['name'], $sid, time() - $idle * 60)) {
                    $client->unload($r['name']);
                    $this->db->table('models')->where('id', $r['model_id'])->update(['status' => 'available']);
                    $done['unloaded'][] = $label;
                }
            } catch (\Throwable $e) {
                $done['errors'][$label] = mb_substr($e->getMessage(), 0, 120);
            }
        }
        return $done;
    }

    /** Pinned ("keep loaded") model names, cached for the request: chat calls pass keep_alive -1 for them. */
    private static ?array $pinned = null;

    /** Forget the cached pinned list (after the registry changes, and between tests). */
    public static function forgetPinned(): void
    {
        self::$pinned = null;
    }

    public static function isPinned(string $model): bool
    {
        $pinned = &self::$pinned;
        if ($pinned === null) {
            try {
                $pinned = array_flip(array_column(\Config\Database::connect()->table('model_registry')->select('name')
                    ->where(['keep_loaded' => 1, 'enabled' => 1])->get()->getResultArray(), 'name'));
            } catch (\Throwable) {
                $pinned = [];
            }
        }
        return isset($pinned[$model]);
    }

    private function usedSince(string $model, int $serverId, int $since): bool
    {
        $t = date('Y-m-d H:i:s', $since);
        if ($this->db->table('api_logs')->where(['model' => $model, 'server_id' => $serverId])->where('created_at >=', $t)->countAllResults()) return true;
        return $this->db->tableExists('chat_messages')
            && $this->db->table('chat_messages')->where('model', $model)->where('created_at >=', $t)->countAllResults() > 0;
    }
}
