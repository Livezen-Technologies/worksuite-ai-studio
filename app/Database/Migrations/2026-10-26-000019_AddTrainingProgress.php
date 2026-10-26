<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Live training progress: the runner (training script or aistudio-train-report) posts step, loss, ETA and machine
 * figures with a per-job token; the Training page shows progress, charts and the log.
 */
class AddTrainingProgress extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('training_jobs', [
            'server_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'dataset_id'],
            'progress_pct'      => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'phase'             => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'current_step'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'total_steps'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'epoch'             => ['type' => 'DECIMAL', 'constraint' => '8,3', 'null' => true],
            'eta_seconds'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'live'              => ['type' => 'TEXT', 'null' => true],
            'started_at'        => ['type' => 'DATETIME', 'null' => true],
            'finished_at'       => ['type' => 'DATETIME', 'null' => true],
            'last_report_at'    => ['type' => 'DATETIME', 'null' => true],
            'report_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
        ]);
        $this->db->query("ALTER TABLE training_jobs MODIFY method ENUM('lora','qlora','finetune') NOT NULL DEFAULT 'qlora'");

        $this->forge->addField([
            'id'       => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'job_id'   => ['type' => 'INT', 'unsigned' => true],
            'at'       => ['type' => 'DATETIME'],
            'step'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'loss'     => ['type' => 'FLOAT', 'null' => true],
            'lr'       => ['type' => 'FLOAT', 'null' => true],
            'cpu_pct'  => ['type' => 'FLOAT', 'null' => true],
            'gpu_pct'  => ['type' => 'FLOAT', 'null' => true],
            'vram_pct' => ['type' => 'FLOAT', 'null' => true],
            'gpu_temp' => ['type' => 'FLOAT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['job_id', 'id']);
        $this->forge->createTable('training_job_points');
    }

    public function down(): void
    {
        $this->forge->dropTable('training_job_points');
        $this->db->query("ALTER TABLE training_jobs MODIFY method ENUM('lora','qlora') NOT NULL DEFAULT 'qlora'");
        $this->forge->dropColumn('training_jobs', ['server_id', 'progress_pct', 'phase', 'current_step', 'total_steps', 'epoch',
            'eta_seconds', 'live', 'started_at', 'finished_at', 'last_report_at', 'report_token_hash']);
    }
}
