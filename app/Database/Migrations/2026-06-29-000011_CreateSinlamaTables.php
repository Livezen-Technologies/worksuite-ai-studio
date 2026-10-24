<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SinLama tables: instruction datasets (for fine-tuning data) and training
 * jobs (LoRA/QLoRA kits run on an external GPU, results imported via Model
 * Studio as GGUF/adapters).
 */
class CreateSinlamaTables extends Migration
{
    public function up(): void
    {
        // datasets — instruction-tuning datasets (Alpaca style)
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 150],
            'description'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'language'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'si'],
            'item_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'token_estimate' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('datasets');

        // dataset_items — one instruction/input/output row each
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'dataset_id'  => ['type' => 'INT', 'unsigned' => true],
            'instruction' => ['type' => 'TEXT'],
            'input'       => ['type' => 'TEXT', 'null' => true],
            'output'      => ['type' => 'MEDIUMTEXT'],
            'tokens'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'hash'        => ['type' => 'CHAR', 'constraint' => 40], // sha1(instruction|input|output)
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['dataset_id', 'hash']);
        $this->forge->addForeignKey('dataset_id', 'datasets', 'id', '', 'CASCADE');
        $this->forge->createTable('dataset_items');

        // training_jobs — LoRA/QLoRA fine-tune jobs (kit generated here, run on GPU)
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'dataset_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'base_model'  => ['type' => 'VARCHAR', 'constraint' => 191], // HF id, e.g. unsloth/Qwen2.5-3B-Instruct
            'method'      => ['type' => "ENUM('lora','qlora')", 'default' => 'qlora'],
            'hyperparams' => ['type' => 'TEXT', 'null' => true], // JSON
            'status'      => ['type' => "ENUM('draft','kit_ready','running','completed','failed','imported')", 'default' => 'draft'],
            'notes'       => ['type' => 'TEXT', 'null' => true],
            'logs'        => ['type' => 'MEDIUMTEXT', 'null' => true],
            'created_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('training_jobs');
    }

    public function down(): void
    {
        $this->forge->dropTable('training_jobs', true);
        $this->forge->dropTable('dataset_items', true);
        $this->forge->dropTable('datasets', true);
    }
}
