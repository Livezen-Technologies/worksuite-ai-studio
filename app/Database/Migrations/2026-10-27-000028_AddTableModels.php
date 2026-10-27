<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Model builder part 4 — table models (classic ML on a CSV), kept in ml_models with kind "table".
 *   task            classification | regression
 *   target          the column it predicts
 *   schema          the input columns (types, example row) a prediction needs
 *   progress        the trainer's last progress line; error when it failed
 *   requested_name  the name it takes when ready (the newest model takes a name; the older one steps aside)
 */
class AddTableModels extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ml_models', [
            'task'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'kind'],
            'target'         => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true, 'after' => 'task'],
            'schema'         => ['type' => 'MEDIUMTEXT', 'null' => true, 'after' => 'metrics'],
            'progress'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'status'],
            'error'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'progress'],
            'requested_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'name'],
            'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ml_models', ['task', 'target', 'schema', 'progress', 'error', 'requested_name', 'created_by']);
    }
}
