<?php

namespace App\Controllers\Api;

use App\Models\ChatSessionModel;
use App\Models\ChatMessageModel;
use App\Models\ModelConfigModel;
use App\Models\ServerModel;
use App\Services\OllamaService;
use App\Services\ProviderService;

/**
 * Chat Playground API — conversation history + streaming chat/generate proxy.
 *
 *   GET    /api/chat/sessions          list my conversations
 *   POST   /api/chat/sessions          create empty conversation
 *   GET    /api/chat/sessions/{id}     conversation + messages
 *   PUT    /api/chat/sessions/{id}     rename
 *   DELETE /api/chat/sessions/{id}     delete
 *   POST   /api/chat                   streaming chat (persists turns)
 *   POST   /api/generate               streaming one-shot (no session)
 */
class ChatController extends BaseApiController
{
    private function sessions(): ChatSessionModel { return new ChatSessionModel(); }
    private function messages(): ChatMessageModel { return new ChatMessageModel(); }

    public function sessions_list()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        return $this->ok($this->sessions()->forUser($this->userId()));
    }

    public function create()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $in = $this->input();
        $id = $this->sessions()->insert([
            'user_id'       => $this->userId(),
            'title'         => trim((string) ($in['title'] ?? '')) ?: 'New chat',
            'model'         => $in['model'] ?? null,
            'server_id'     => $in['server_id'] ?? null,
            'system_prompt' => $in['system_prompt'] ?? null,
        ], true);
        return $this->ok($this->sessions()->find($id), 'Conversation created.', 201);
    }

    public function show($id = null)
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $session = $this->ownedSession((int) $id);
        if (! $session) return $this->failure('Conversation not found.', 404);
        $session['messages'] = $this->messages()->forSession((int) $id);
        return $this->ok($session);
    }

    public function rename($id = null)
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        if (! $this->ownedSession((int) $id)) return $this->failure('Conversation not found.', 404);
        $title = trim((string) ($this->input()['title'] ?? ''));
        if ($title === '') return $this->failure('Title is required.', 422);
        $this->sessions()->update((int) $id, ['title' => $title]);
        return $this->ok($this->sessions()->find((int) $id), 'Renamed.');
    }

    public function destroy($id = null)
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        if (! $this->ownedSession((int) $id)) return $this->failure('Conversation not found.', 404);
        $this->sessions()->delete((int) $id);
        return $this->ok(null, 'Conversation deleted.');
    }

    /**
     * Streaming chat. Body: { session_id?, server_id, model, content, system_prompt? }.
     * Persists the user turn, streams the assistant reply (NDJSON: meta line, then
     * {delta}, then {done}), and persists the assistant turn with token stats.
     */
    public function chat()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $in       = $this->input();
        $serverId = (int) ($in['server_id'] ?? 0);
        $model    = trim((string) ($in['model'] ?? ''));
        $content  = (string) ($in['content'] ?? '');
        // Base64 image(s) for vision models (data-URI prefix stripped).
        $images   = array_values(array_filter(array_map(
            static fn ($s) => preg_replace('#^data:image/[^;]+;base64,#', '', (string) $s),
            (array) ($in['images'] ?? []),
        )));
        // A vision request may carry only an image; default the prompt.
        if (trim($content) === '' && $images) {
            $content = 'Describe this image.';
        }
        if (! $serverId || $model === '' || trim($content) === '') {
            return $this->failure('server_id, model and content (or an image) are required.', 422);
        }
        $server = (new ServerModel())->find($serverId);
        if (! $server) return $this->failure('Server not found.', 404);
        if (! (int) ($server['is_enabled'] ?? 1)) return $this->failure('This node is disabled. Choose another server or enable it on the Servers page.', 409);

        // Resolve or create the session.
        $sessionId = (int) ($in['session_id'] ?? 0);
        $sessions  = $this->sessions();
        if ($sessionId) {
            if (! $this->ownedSession($sessionId)) return $this->failure('Conversation not found.', 404);
        } else {
            $sessionId = $sessions->insert([
                'user_id'       => $this->userId(),
                'title'         => mb_substr(trim($content), 0, 60),
                'model'         => $model,
                'server_id'     => $serverId,
                'system_prompt' => $in['system_prompt'] ?? null,
            ], true);
        }
        $session = $sessions->find($sessionId);

        // Persist the user turn.
        $this->messages()->insert([
            'session_id' => $sessionId, 'role' => 'user', 'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Assemble the message list (+ optional system prompt).
        $cfg      = (new ModelConfigModel())->ollamaOptions($model);
        $messages = $this->messages()->asOllamaMessages($sessionId);
        $system   = $session['system_prompt'] ?: $cfg['system'];
        if ($system && ($messages[0]['role'] ?? '') !== 'system') {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }
        // Attach images to the most recent user turn (Ollama vision format).
        if ($images) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $messages[$i]['images'] = $images;
                    break;
                }
            }
        }
        $extra = ['options' => $cfg['options'], 'keep_alive' => $cfg['keep_alive']];

        // Stream.
        $this->beginStream();
        $emit = static function (array $line) { echo json_encode($line) . "\n"; flush(); };
        $emit(['meta' => true, 'session_id' => $sessionId, 'title' => $session['title']]);

        $assistant = '';
        try {
            $final = (new ProviderService())->chat($server, $model, $messages, $extra,
                static function (string $delta) use (&$assistant, $emit) {
                    $assistant .= $delta;
                    $emit(['delta' => $delta]);
                });
            $this->messages()->insert([
                'session_id'        => $sessionId, 'role' => 'assistant', 'content' => $assistant, 'model' => $model,
                'prompt_tokens'     => $final['prompt_tokens'] ?? null,
                'completion_tokens' => $final['completion_tokens'] ?? null,
                'total_ms'          => $final['total_ms'] ?? null,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            $sessions->touch($sessionId);
            $emit([
                'done' => true, 'session_id' => $sessionId,
                'prompt_tokens' => $final['prompt_tokens'] ?? null,
                'completion_tokens' => $final['completion_tokens'] ?? null,
                'total_ms' => $final['total_ms'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $emit(['error' => $e->getMessage(), 'session_id' => $sessionId, 'done' => true]);
        }
        exit;
    }

    /** Streaming one-shot generate (no persistence). Body: { server_id, model, prompt }. */
    public function generate()
    {
        if ($r = $this->requirePermission('chat.use')) return $r;
        $in       = $this->input();
        $serverId = (int) ($in['server_id'] ?? 0);
        $model    = trim((string) ($in['model'] ?? ''));
        $prompt   = (string) ($in['prompt'] ?? '');
        if (! $serverId || $model === '' || trim($prompt) === '') {
            return $this->failure('server_id, model and prompt are required.', 422);
        }
        $server = (new ServerModel())->find($serverId);
        if (! $server) return $this->failure('Server not found.', 404);
        if (! (int) ($server['is_enabled'] ?? 1)) return $this->failure('This node is disabled. Choose another server or enable it on the Servers page.', 409);

        $cfg = (new ModelConfigModel())->ollamaOptions($model);
        $this->beginStream();
        $emit = static function (array $line) { echo json_encode($line) . "\n"; flush(); };

        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null);
        try {
            $client->generate($model, $prompt, ['options' => $cfg['options'], 'keep_alive' => $cfg['keep_alive']],
                function (array $line) use ($emit) {
                    if (($line['response'] ?? '') !== '') $emit(['delta' => $line['response']]);
                });
            $emit(['done' => true]);
        } catch (\Throwable $e) {
            $emit(['error' => $e->getMessage(), 'done' => true]);
        }
        exit;
    }

    /** Switch the response to unbuffered NDJSON streaming. */
    private function beginStream(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: application/x-ndjson');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }

    /** Return the session if it exists and belongs to the current user, else null. */
    private function ownedSession(int $id): ?array
    {
        $s = $this->sessions()->find($id);
        return ($s && (int) $s['user_id'] === $this->userId()) ? $s : null;
    }
}
