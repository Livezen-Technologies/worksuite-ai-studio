<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateModelTables extends Migration
{
    public function up(): void
    {
        // models — a model as installed on a specific node (server_id + name unique)
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'server_id'      => ['type' => 'INT', 'unsigned' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 191], // e.g. llama3:8b
            'family'         => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'parameter_size' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'quantization'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'format'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'size_bytes'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'digest'         => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'context_length' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status'         => ['type' => "ENUM('available','loading','running','pulling','error')", 'default' => 'available'],
            'is_default'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'modified_at'    => ['type' => 'DATETIME', 'null' => true],
            'synced_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['server_id', 'name']);
        $this->forge->addForeignKey('server_id', 'servers', 'id', '', 'CASCADE');
        $this->forge->createTable('models');

        // model_configs — per-model generation parameters (keyed by model name)
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 191], // model name, unique
            'temperature'    => ['type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 0.80],
            'top_p'          => ['type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 0.90],
            'top_k'          => ['type' => 'INT', 'default' => 40],
            'repeat_penalty' => ['type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 1.10],
            'num_ctx'        => ['type' => 'INT', 'default' => 4096],
            'num_predict'    => ['type' => 'INT', 'default' => -1],
            'stop'           => ['type' => 'TEXT', 'null' => true], // JSON array of stop sequences
            'seed'           => ['type' => 'INT', 'null' => true],
            'num_batch'      => ['type' => 'INT', 'null' => true],
            'num_thread'     => ['type' => 'INT', 'null' => true],
            'num_gpu'        => ['type' => 'INT', 'null' => true],
            'keep_alive'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => '5m'],
            'system_prompt'  => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('model_configs');
    }

    public function down(): void
    {
        $this->forge->dropTable('model_configs', true);
        $this->forge->dropTable('models', true);
    }
}
