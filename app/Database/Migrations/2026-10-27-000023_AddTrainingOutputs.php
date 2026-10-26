<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Custom LLMs: what a pipeline produces (`output`: Ollama name, quantization, system prompt) and, per job, the model it
 * produced and where its finalisation (merge → GGUF → quantize → Ollama) stands.
 */
class AddTrainingOutputs extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('training_pipelines', [
            'output' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addColumn('training_jobs', [
            'output_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],   // building | ready | failed
            'output_model'  => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'output_path'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('training_pipelines', ['output']);
        $this->forge->dropColumn('training_jobs', ['output_status', 'output_model', 'output_path']);
    }
}
