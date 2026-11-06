<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indexes for the time-bounded queries: gateway least-busy routing and per-server usage
 * (api_logs by server and time) and analytics over Playground replies (chat_messages by time).
 */
class AddActivityIndexes extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE INDEX api_logs_server_created ON ' . $this->db->prefixTable('api_logs') . ' (server_id, created_at)');
        $this->db->query('CREATE INDEX chat_messages_role_created ON ' . $this->db->prefixTable('chat_messages') . ' (role, created_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX api_logs_server_created ON ' . $this->db->prefixTable('api_logs'));
        $this->db->query('DROP INDEX chat_messages_role_created ON ' . $this->db->prefixTable('chat_messages'));
    }
}
