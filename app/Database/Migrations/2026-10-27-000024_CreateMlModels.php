<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Trained ML models served by the Studio's ML service (text classifiers now; table models later): where the model
 * lives, its labels and its measured quality. Called from apps by name via /api/v1/classify.
 */
class CreateMlModels extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'kind'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'classifier'],
            'job_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'pipeline_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'dataset_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'base_model'  => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'path'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'labels'      => ['type' => 'TEXT', 'null' => true],
            'metrics'     => ['type' => 'TEXT', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ready'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('ml_models');
    }

    public function down(): void
    {
        $this->forge->dropTable('ml_models', true);
    }
}
