<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateServerTables extends Migration
{
    public function up(): void
    {
        // servers — registered Ollama nodes
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'description'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'hostname'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'ip_address'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'api_url'        => ['type' => 'VARCHAR', 'constraint' => 255], // http://host:11434
            'port'           => ['type' => 'INT', 'unsigned' => true, 'default' => 11434],
            'auth_token'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'gpu_model'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'cuda_version'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'cpu_cores'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ram_gb'         => ['type' => 'DECIMAL', 'constraint' => '6,1', 'null' => true],
            'storage_gb'     => ['type' => 'DECIMAL', 'constraint' => '8,1', 'null' => true],
            'os'             => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'ollama_version' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'is_local'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'         => ['type' => "ENUM('online','offline','degraded','unknown')", 'default' => 'unknown'],
            'health_score'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'last_heartbeat_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('servers');

        // server_health — time-series health probe results
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'server_id'      => ['type' => 'INT', 'unsigned' => true],
            'status'         => ['type' => "ENUM('online','offline','degraded','unknown')", 'default' => 'unknown'],
            'health_score'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'latency_ms'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'models_total'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'models_running' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'error'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'checked_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['server_id', 'checked_at']);
        $this->forge->addForeignKey('server_id', 'servers', 'id', '', 'CASCADE');
        $this->forge->createTable('server_health');
    }

    public function down(): void
    {
        $this->forge->dropTable('server_health', true);
        $this->forge->dropTable('servers', true);
    }
}
