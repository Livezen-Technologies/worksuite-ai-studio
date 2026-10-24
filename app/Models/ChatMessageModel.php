<?php

namespace App\Models;

use CodeIgniter\Model;

class ChatMessageModel extends Model
{
    protected $table         = 'chat_messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'session_id', 'role', 'content', 'model',
        'prompt_tokens', 'completion_tokens', 'total_ms', 'created_at',
    ];

    public function forSession(int $sessionId): array
    {
        return $this->where('session_id', $sessionId)->orderBy('id', 'ASC')->find();
    }

    /** Role/content pairs ready to send to Ollama's /api/chat. */
    public function asOllamaMessages(int $sessionId): array
    {
        $rows = $this->forSession($sessionId);
        return array_map(static fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $rows);
    }
}
