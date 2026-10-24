<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WorkSuite Sinhala AI Engine — build & training zone (SRS §3.1, §10.1, §16, §17).
 *
 * AI Studio is the "separate build and training zone": it holds dataset
 * provenance, training lineage, evaluation reports and the signed offline
 * packages that the per-user desktop engine imports. Nothing here is shipped
 * to end-user devices except signed manifests + artifacts.
 */
class CreateEngineReleaseZone extends Migration
{
    public function up(): void
    {
        // FR TRNPIPE 001 / 003 — provenance, rights and frozen splits on datasets.
        $this->forge->addColumn('datasets', [
            'task'            => ['type' => "ENUM('text','asr','tts','translation','transliteration','spelling','resource')", 'default' => 'text', 'after' => 'language'],
            'source_ref'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'permitted_use'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'rights_ref'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'consent_required'=> ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'consent_ref'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'rights_status'   => ['type' => "ENUM('quarantined','approved','withdrawn')", 'default' => 'quarantined'],
            'version'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'manifest_hash'   => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'split_hash'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'frozen_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addColumn('dataset_items', [
            'split'       => ['type' => "ENUM('train','dev','test')", 'null' => true],
            'group_key'   => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true], // speaker / document / session
        ]);

        // FR TRNPIPE 002 — reproducible run lineage.
        $this->forge->addColumn('training_jobs', [
            'task'                  => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'text'],
            'dataset_manifest_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'dataset_split_hash'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'base_digest'           => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'config_hash'           => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'code_commit'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'container_digest'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'seed'                  => ['type' => 'INT', 'null' => true],
            'hardware'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'output_digest'         => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
        ]);

        // §17.4 — evaluation reports.
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'             => ['type' => 'CHAR', 'constraint' => 36],
            'task'             => ['type' => 'VARCHAR', 'constraint' => 30],
            'training_job_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'candidate_digest' => ['type' => 'CHAR', 'constraint' => 64],
            'baseline_digest'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'suite_digest'     => ['type' => 'CHAR', 'constraint' => 64],
            'hardware'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'runtime_lock'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'metrics'          => ['type' => 'MEDIUMTEXT'],            // JSON {metric: value}
            'baseline_metrics' => ['type' => 'MEDIUMTEXT', 'null' => true],
            'subgroups'        => ['type' => 'MEDIUMTEXT', 'null' => true], // JSON [{name,n,metrics}]
            'gate_result'      => ['type' => 'MEDIUMTEXT', 'null' => true], // JSON computed by EngineGateService
            'decision'         => ['type' => "ENUM('pending','pass','fail')", 'default' => 'pending'],
            'release_approver' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'language_approver'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'notes'            => ['type' => 'TEXT', 'null' => true],
            'created_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['task', 'decision']);
        $this->forge->createTable('engine_evaluations');

        // §10.1 / §14.5 — signed model packages and their lifecycle.
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'package_id'         => ['type' => 'CHAR', 'constraint' => 36],
            'version'            => ['type' => 'VARCHAR', 'constraint' => 30],
            'task'               => ['type' => 'VARCHAR', 'constraint' => 30],
            'languages'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'runtime_name'       => ['type' => 'VARCHAR', 'constraint' => 50],
            'runtime_range'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'base_model'         => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'quantization'       => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'artifacts'          => ['type' => 'MEDIUMTEXT'],          // JSON [{path,sha256,size_bytes}]
            'license_id'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'rights_approval_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'evaluation_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'training_job_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'min_ram_gib'        => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'min_disk_gib'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'state'              => ['type' => "ENUM('candidate','quarantined','verified','evaluated','approved','staged','active','retired','revoked')", 'default' => 'candidate'],
            'manifest'           => ['type' => 'MEDIUMTEXT', 'null' => true], // exact signed UTF-8 bytes
            'manifest_sha256'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'signature'          => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true], // base64 Ed25519
            'publisher_key_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'state_history'      => ['type' => 'MEDIUMTEXT', 'null' => true], // JSON audit trail (content free)
            'created_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('package_id');
        $this->forge->addKey('manifest_sha256');
        $this->forge->addKey(['task', 'state']);
        $this->forge->createTable('engine_packages');

        $db = \Config\Database::connect();
        if ($db->tableExists('permissions')) {
            $now = date('Y-m-d H:i:s');
            foreach ([['engine.view', 'View Sinhala engine releases'], ['engine.manage', 'Manage engine evaluations/packages'], ['engine.sign', 'Sign and revoke engine packages']] as [$slug, $label]) {
                if (! $db->table('permissions')->where('name', $slug)->countAllResults()) {
                    $db->table('permissions')->insert(['name' => $slug, 'label' => $label, 'module' => 'engine', 'created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('engine_packages', true);
        $this->forge->dropTable('engine_evaluations', true);
        $this->forge->dropColumn('training_jobs', ['task', 'dataset_manifest_hash', 'dataset_split_hash', 'base_digest', 'config_hash', 'code_commit', 'container_digest', 'seed', 'hardware', 'output_digest']);
        $this->forge->dropColumn('dataset_items', ['split', 'group_key']);
        $this->forge->dropColumn('datasets', ['task', 'source_ref', 'permitted_use', 'rights_ref', 'consent_required', 'consent_ref', 'rights_status', 'version', 'manifest_hash', 'split_hash', 'frozen_at']);
        \Config\Database::connect()->table('permissions')->whereIn('name', ['engine.view', 'engine.manage', 'engine.sign'])->delete();
    }
}
