<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGatewayTables extends Migration
{
    public function up(): void
    {
        // api_keys — external application credentials for the /api/v1 gateway
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'                 => ['type' => 'VARCHAR', 'constraint' => 120],
            'user_id'              => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'api_key'              => ['type' => 'VARCHAR', 'constraint' => 64],  // public id (ohub_...)
            'secret_hash'          => ['type' => 'VARCHAR', 'constraint' => 255], // hash of the secret
            'secret_prefix'        => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true], // sk_xxxx… for display
            'scopes'               => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => 'chat,generate,embeddings,models'],
            'allowed_models'       => ['type' => 'TEXT', 'null' => true], // JSON array, null = all
            'allowed_ips'          => ['type' => 'TEXT', 'null' => true], // JSON array, null = any
            'daily_token_limit'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'monthly_token_limit'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'rate_limit_per_min'   => ['type' => 'INT', 'unsigned' => true, 'default' => 60],
            'status'               => ['type' => "ENUM('active','disabled')", 'default' => 'active'],
            'expires_at'           => ['type' => 'DATETIME', 'null' => true],
            'last_used_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('api_key');
        $this->forge->createTable('api_keys');

        // api_logs — one row per gateway request
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'api_key_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'user_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'endpoint'          => ['type' => 'VARCHAR', 'constraint' => 64],
            'model'             => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'server_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'method'            => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'POST'],
            'prompt_tokens'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'completion_tokens' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'total_tokens'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'processing_ms'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status'            => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 200],
            'client_ip'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'country'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['api_key_id', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->addKey('status');
        $this->forge->createTable('api_logs');
    }

    public function down(): void
    {
        $this->forge->dropTable('api_logs', true);
        $this->forge->dropTable('api_keys', true);
    }
}
