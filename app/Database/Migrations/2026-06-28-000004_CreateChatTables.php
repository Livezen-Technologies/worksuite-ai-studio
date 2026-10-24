<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateChatTables extends Migration
{
    public function up(): void
    {
        // chat_sessions — one conversation
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'INT', 'unsigned' => true],
            'title'         => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => 'New chat'],
            'model'         => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'server_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'system_prompt' => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'updated_at']);
        $this->forge->createTable('chat_sessions');

        // chat_messages — turns within a session
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'session_id'        => ['type' => 'INT', 'unsigned' => true],
            'role'              => ['type' => "ENUM('system','user','assistant')", 'default' => 'user'],
            'content'           => ['type' => 'MEDIUMTEXT'],
            'model'             => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'prompt_tokens'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'completion_tokens' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'total_ms'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['session_id', 'id']);
        $this->forge->addForeignKey('session_id', 'chat_sessions', 'id', '', 'CASCADE');
        $this->forge->createTable('chat_messages');
    }

    public function down(): void
    {
        $this->forge->dropTable('chat_messages', true);
        $this->forge->dropTable('chat_sessions', true);
    }
}
