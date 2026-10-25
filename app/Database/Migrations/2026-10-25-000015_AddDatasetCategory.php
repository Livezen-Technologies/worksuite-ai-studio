<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Training category per dataset (instruction, qa, transliteration, translation, corpus, speech, evaluation, reference).
 * Set by `spark datasets:organize`; the training mix draws a fixed share from each trainable category.
 */
class AddDatasetCategory extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('datasets', [
            'category' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'task'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('datasets', 'category');
    }
}
