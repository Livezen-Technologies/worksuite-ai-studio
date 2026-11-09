<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AI Model Registry: one row per logical model (type, version, capabilities, VRAM need, access),
 * its assignment to nodes with a deployment state, and the default model per task / application.
 * The per-node `models` table stays the record of what each node actually has installed.
 */
class CreateModelRegistry extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 191],               // engine model id, e.g. llama3.1:8b
            'display_name'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'type'                => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'llm'], // llm|embedding|stt|tts|vision|multimodal
            'version'             => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'family'              => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'parameter_size'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'quantization'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'vram_gb'             => ['type' => 'DECIMAL', 'constraint' => '6,1', 'null' => true],
            'context_length'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'capabilities'        => ['type' => 'TEXT', 'null' => true],                     // JSON list
            'engine'              => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'ollama'],
            'source'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'description'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'access_roles'        => ['type' => 'TEXT', 'null' => true],                     // JSON role names; null = everyone
            'keep_loaded'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'idle_unload_minutes' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'enabled'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('model_registry', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'registry_id' => ['type' => 'INT', 'unsigned' => true],
            'server_id'   => ['type' => 'INT', 'unsigned' => true],
            'state'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'], // pending|deploying|deployed|failed|removing
            'job_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'error'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['registry_id', 'server_id']);
        $this->forge->createTable('model_assignments', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'task'        => ['type' => 'VARCHAR', 'constraint' => 24],
            'application' => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => ''],   // '' = every application
            'model_name'  => ['type' => 'VARCHAR', 'constraint' => 191],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['task', 'application']);
        $this->forge->createTable('model_defaults', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('model_defaults', true);
        $this->forge->dropTable('model_assignments', true);
        $this->forge->dropTable('model_registry', true);
    }
}
