<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Automated training: pipelines, the node training agent, and the job states between them.
 *
 *   pipeline (recipe + dataset + node choice) ──dataset changes──▶ job: preparing → queued → running → completed | failed
 *                                                                      (server stages + validates)  (agent claims)
 * A node's training agent polls /api/agent/heartbeat with its own token, claims a queued job it can run, fetches the
 * staged files, trains and reports. A job that stops reporting is retried, then failed, with a notification.
 */
class CreateTrainingPipelines extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 150],
            'recipe'            => ['type' => 'VARCHAR', 'constraint' => 40],
            'dataset_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'server_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true], // null = any capable node
            'base_model'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'hyperparams'       => ['type' => 'TEXT', 'null' => true],
            'auto_trigger'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'enabled'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'min_rows'          => ['type' => 'INT', 'unsigned' => true, 'default' => 200],
            'max_attempts'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 2],
            'last_fingerprint'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'last_job_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'last_triggered_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('training_pipelines');

        $this->db->query("ALTER TABLE training_jobs MODIFY status ENUM('draft','kit_ready','preparing','queued','running','completed','failed','cancelled','imported') NOT NULL DEFAULT 'draft'");
        $this->forge->addColumn('training_jobs', [
            'pipeline_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'id'],
            'recipe'           => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'trigger_reason'   => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'attempt'          => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'max_attempts'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 2],
            'error'            => ['type' => 'TEXT', 'null' => true],
            'claimed_at'       => ['type' => 'DATETIME', 'null' => true],
            'cancel_requested' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);
        $this->forge->addColumn('servers', [
            'agent_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'agent_seen_at'    => ['type' => 'DATETIME', 'null' => true],
            'agent_version'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'agent_job_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('training_pipelines', true);
        $this->forge->dropColumn('training_jobs', ['pipeline_id', 'recipe', 'trigger_reason', 'attempt', 'max_attempts', 'error', 'claimed_at', 'cancel_requested']);
        $this->db->query("ALTER TABLE training_jobs MODIFY status ENUM('draft','kit_ready','running','completed','failed','imported') NOT NULL DEFAULT 'draft'");
        $this->forge->dropColumn('servers', ['agent_token_hash', 'agent_seen_at', 'agent_version', 'agent_job_id']);
    }
}
