<?php

namespace App\Models;

use CodeIgniter\Model;

class ChatSessionModel extends Model
{
    protected $table         = 'chat_sessions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['user_id', 'title', 'model', 'server_id', 'system_prompt'];

    /** A user's sessions, most-recently-updated first, with message counts. */
    public function forUser(int $userId): array
    {
        return $this->db->table('chat_sessions cs')
            ->select('cs.*, COUNT(cm.id) AS message_count')
            ->join('chat_messages cm', 'cm.session_id = cs.id', 'left')
            ->where('cs.user_id', $userId)
            ->groupBy('cs.id')
            ->orderBy('cs.updated_at', 'DESC')
            ->get()->getResultArray();
    }

    public function touch(int $id): void
    {
        // Bump updated_at via the builder — bypasses allowedFields (which doesn't
        // list updated_at), avoiding CI4's "no data to update" on an otherwise
        // timestamp-only update.
        $this->builder()->where('id', $id)->update(['updated_at' => date('Y-m-d H:i:s')]);
    }
}
