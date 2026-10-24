<?php

namespace App\Controllers\Api;

use App\Models\ServerModel;
use App\Services\ModelService;
use App\Services\OllamaService;

/**
 * Model Studio — build custom (SinLama) models on an Ollama node.
 *
 *   GET  /api/studio/presets   SinLama persona/parameter presets
 *   POST /api/studio/upload    upload a GGUF / LoRA adapter → blob digest
 *   POST /api/studio/create    create a model (streams NDJSON progress)
 *   POST /api/studio/copy      clone an existing model under a new name
 *
 * Uses Ollama's structured /api/create (from + system + template + parameters
 * + files/adapters + quantize) so a "SinLama-3B" is a base model plus a Sinhala
 * persona — created in seconds on the node, no GPU required. Trained adapters
 * or full GGUFs from the Training Manager are imported the same way.
 */
class ModelStudioController extends BaseApiController
{
    private function servers(): ServerModel { return new ServerModel(); }

    public function presets()
    {
        if ($r = $this->requirePermission('models.view')) return $r;
        return $this->ok(self::PRESETS);
    }

    /**
     * Upload a GGUF or LoRA adapter file, push it to the node's blob store and
     * return the digest for use in create()'s files/adapters maps.
     * Multipart body: file, server_id.
     */
    public function upload()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $serverId = (int) ($this->request->getPost('server_id') ?? 0);
        $server   = $this->servers()->find($serverId);
        if (! $server) return $this->failure('Server not found.', 404);
        if (($server['kind'] ?? 'ollama') !== 'ollama') {
            return $this->failure('Files can only be uploaded to Ollama nodes.', 422);
        }

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->failure('No file received (check the upload size limit).', 422);
        }

        $tmp    = $file->getTempName();
        $digest = hash_file('sha256', $tmp);
        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null);

        try {
            if (! $client->blobExists($digest)) {
                $client->pushBlob($digest, $tmp);
            }
        } catch (\Throwable $e) {
            return $this->failure('Upload to node failed: ' . $e->getMessage(), 502);
        }

        return $this->ok([
            'digest'   => 'sha256:' . $digest,
            'filename' => $file->getClientName(),
            'size'     => $file->getSize(),
        ], 'File uploaded to node.');
    }

    /**
     * Create a model. Body:
     * { server_id, name, from?, system?, template?, parameters?{}, quantize?,
     *   files?{filename:digest}, adapters?{filename:digest} }
     * Streams NDJSON progress lines, then re-syncs the node's model list.
     */
    public function create()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $in       = $this->input();
        $serverId = (int) ($in['server_id'] ?? 0);
        $name     = trim((string) ($in['name'] ?? ''));
        if (! $serverId || $name === '') {
            return $this->failure('server_id and model name are required.', 422);
        }
        if (! preg_match('/^[a-z0-9][a-z0-9._\/-]*(:[a-z0-9._-]+)?$/i', $name)) {
            return $this->failure('Model name may only contain letters, numbers, ".", "_", "-" and an optional :tag.', 422);
        }
        $server = $this->servers()->find($serverId);
        if (! $server) return $this->failure('Server not found.', 404);
        if (($server['kind'] ?? 'ollama') !== 'ollama') {
            return $this->failure('Custom models can only be created on Ollama nodes.', 422);
        }

        // Assemble the structured create spec.
        $spec = ['model' => $name];
        foreach (['from', 'system', 'template', 'quantize'] as $f) {
            if (! empty($in[$f]) && is_string($in[$f])) {
                $spec[$f] = $in[$f];
            }
        }
        foreach (['files', 'adapters'] as $f) {
            if (! empty($in[$f]) && is_array($in[$f])) {
                $spec[$f] = $in[$f];
            }
        }
        if (! empty($in['parameters']) && is_array($in['parameters'])) {
            $params = [];
            foreach ($in['parameters'] as $k => $v) {
                if ($v === '' || $v === null) continue;
                $params[$k] = is_numeric($v) ? $v + 0 : $v;
            }
            if ($params) $spec['parameters'] = $params;
        }
        if (empty($spec['from']) && empty($spec['files'])) {
            return $this->failure('Pick a base model (from) or upload a GGUF file.', 422);
        }

        // Stream NDJSON progress.
        while (ob_get_level() > 0) { ob_end_flush(); }
        header('Content-Type: application/x-ndjson');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        $emit = static function (array $line) { echo json_encode($line) . "\n"; flush(); };

        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null, 1800);
        try {
            $client->createModel($spec, $emit);
            (new ModelService())->syncServer($server);
            $emit(['status' => 'success', 'done' => true, 'model' => $name]);
        } catch (\Throwable $e) {
            $emit(['error' => $e->getMessage(), 'done' => true]);
        }
        exit;
    }

    /** Clone a model. Body: { server_id, source, destination }. */
    public function copyModel()
    {
        if ($r = $this->requirePermission('models.manage')) return $r;
        $in     = $this->input();
        $server = $this->servers()->find((int) ($in['server_id'] ?? 0));
        $src    = trim((string) ($in['source'] ?? ''));
        $dst    = trim((string) ($in['destination'] ?? ''));
        if (! $server || $src === '' || $dst === '') {
            return $this->failure('server_id, source and destination are required.', 422);
        }
        try {
            (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->copy($src, $dst);
            (new ModelService())->syncServer($server);
        } catch (\Throwable $e) {
            return $this->failure('Copy failed: ' . $e->getMessage(), 502);
        }
        return $this->ok(null, "Cloned {$src} → {$dst}.");
    }

    /**
     * SinLama presets: Sinhala persona/system prompts + recommended parameters.
     * Content intentionally lives in code so it ships with the platform.
     */
    private const PRESETS = [
        [
            'key'    => 'sinlama-assistant',
            'label'  => 'SinLama Assistant (Sinhala persona)',
            'name'   => 'sinlama',
            'system' => "You are SinLama AI (සින්ලාමා), an enterprise Sinhala AI assistant built for Sri Lankan users.\n\nRules:\n- Always answer in natural, fluent Sinhala (සිංහල) unless the user writes in English — then answer in English.\n- Understand Sinhala, English and Singlish (Sinhala written in Latin letters) equally well.\n- Use Sri Lankan terminology, currency (රු. / LKR), date formats and examples.\n- Keep technical terms (Windows, install, email…) in English where Sinhala speakers normally do.\n- When the user asks for JSON or code, output only valid JSON/code with no commentary.\n- Be concise, polite and helpful.",
            'parameters' => ['temperature' => 0.7, 'num_ctx' => 4096],
        ],
        [
            'key'    => 'sinlama-translator',
            'label'  => 'SinLama Translator (si ⇄ en)',
            'name'   => 'sinlama-translator',
            'system' => "You are SinLama Translator, a precise Sinhala ⇄ English translation engine.\n\nRules:\n- Detect the input language automatically: Sinhala → translate to English; English → translate to Sinhala.\n- Handle Singlish input (Sinhala in Latin letters) as Sinhala.\n- Output ONLY the translation — no explanations, no notes, no quotes.\n- Preserve tone, register, names, numbers and formatting.\n- Use natural, contemporary Sinhala — not stiff literal translation.",
            'parameters' => ['temperature' => 0.2, 'num_ctx' => 4096],
        ],
        [
            'key'    => 'sinlama-support',
            'label'  => 'SinLama Support Agent (customer support)',
            'name'   => 'sinlama-support',
            'system' => "You are SinLama Support, a friendly Sri Lankan customer-support agent.\n\nRules:\n- Reply in the customer's language (Sinhala, English or Singlish → reply in Sinhala for Singlish).\n- Be warm, apologetic when needed, and solution-focused. Short paragraphs.\n- Ask at most one clarifying question at a time.\n- Never invent order numbers, prices or policies — say you will check instead.\n- End with a short helpful closing (e.g. ස්තූතියි!).",
            'parameters' => ['temperature' => 0.6, 'num_ctx' => 4096],
        ],
        [
            'key'    => 'sinlama-writer',
            'label'  => 'SinLama Writer (SEO / marketing, Sinhala)',
            'name'   => 'sinlama-writer',
            'system' => "You are SinLama Writer, a Sinhala content and SEO writer.\n\nRules:\n- Write engaging, grammatically correct Sinhala content (blog posts, ads, social captions, emails).\n- Follow the requested format exactly (headings, bullet lists, word counts).\n- Weave in the given keywords naturally for SEO.\n- Match the requested tone (formal / casual / promotional).",
            'parameters' => ['temperature' => 0.9, 'num_ctx' => 4096],
        ],
        [
            'key'    => 'sinlama-coder',
            'label'  => 'SinLama Code (bilingual coding help)',
            'name'   => 'sinlama-code',
            'system' => "You are SinLama Code, a programming assistant for Sri Lankan developers.\n\nRules:\n- Explain concepts in Sinhala (or the user's language) but keep ALL code, identifiers and error messages in English.\n- Give working, complete code examples.\n- Point out bugs plainly and show the fixed code.",
            'parameters' => ['temperature' => 0.3, 'num_ctx' => 8192],
        ],
    ];
}
