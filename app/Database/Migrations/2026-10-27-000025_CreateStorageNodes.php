<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Storage nodes (part 1): local disks, network mounts and S3-compatible buckets that AI Studio can store data on —
 * their lifecycle, health checks with history, allocations to apps / users / services, and alert rules.
 *
 *   lifecycle (status): testing → active ⇄ draining → retired          health: ok | degraded | down | unknown
 */
class CreateStorageNodes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'kind'           => ['type' => 'VARCHAR', 'constraint' => 20],               // local | mounted | s3 | vps
            'status'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'testing'],
            'health'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'unknown'],
            'config'         => ['type' => 'TEXT', 'null' => true],                      // path / endpoint / bucket / region …
            'secret'         => ['type' => 'TEXT', 'null' => true],                      // encrypted credentials
            'region'         => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'tags'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'priority'       => ['type' => 'INT', 'default' => 100],
            'readonly'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'quota_bytes'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true], // capacity to use (S3 has none of its own)
            'total_bytes'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'used_bytes'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'free_bytes'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'write_mbps'     => ['type' => 'FLOAT', 'null' => true],
            'read_mbps'      => ['type' => 'FLOAT', 'null' => true],
            'latency_ms'     => ['type' => 'FLOAT', 'null' => true],
            'last_check_at'  => ['type' => 'DATETIME', 'null' => true],
            'last_error'     => ['type' => 'TEXT', 'null' => true],
            'status_history' => ['type' => 'TEXT', 'null' => true],
            'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'health']);
        $this->forge->createTable('storage_nodes');

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'node_id'    => ['type' => 'INT', 'unsigned' => true],
            'at'         => ['type' => 'DATETIME'],
            'health'     => ['type' => 'VARCHAR', 'constraint' => 20],
            'total_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'used_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'write_mbps' => ['type' => 'FLOAT', 'null' => true],
            'read_mbps'  => ['type' => 'FLOAT', 'null' => true],
            'latency_ms' => ['type' => 'FLOAT', 'null' => true],
            'error'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['node_id', 'at']);
        $this->forge->createTable('storage_checks');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'node_id'     => ['type' => 'INT', 'unsigned' => true],
            'scope_type'  => ['type' => 'VARCHAR', 'constraint' => 20],    // app | user | service | organization
            'scope_id'    => ['type' => 'VARCHAR', 'constraint' => 120],
            'quota_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'note'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('node_id');
        $this->forge->createTable('storage_allocations');

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 120],
            'metric'        => ['type' => 'VARCHAR', 'constraint' => 20],   // used_pct | free_gb | latency_ms | write_mbps | down
            'op'            => ['type' => 'VARCHAR', 'constraint' => 2],    // gt | lt
            'threshold'     => ['type' => 'DOUBLE', 'default' => 0],
            'level'         => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'warning'],   // warning | error
            'node_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],              // null = every node
            'enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('storage_alert_rules');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'rule_id'     => ['type' => 'INT', 'unsigned' => true],
            'node_id'     => ['type' => 'INT', 'unsigned' => true],
            'level'       => ['type' => 'VARCHAR', 'constraint' => 10],
            'message'     => ['type' => 'VARCHAR', 'constraint' => 500],
            'value'       => ['type' => 'DOUBLE', 'null' => true],
            'opened_at'   => ['type' => 'DATETIME'],
            'resolved_at' => ['type' => 'DATETIME', 'null' => true],
            'acknowledged_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['node_id', 'resolved_at']);
        $this->forge->createTable('storage_alerts');

        $now = date('Y-m-d H:i:s');
        // Sensible starting rules; edit them on the Storage page.
        $this->db->table('storage_alert_rules')->insertBatch([
            ['name' => 'Node unreachable', 'metric' => 'down', 'op' => 'gt', 'threshold' => 0, 'level' => 'error', 'created_at' => $now],
            ['name' => 'Disk 80% full', 'metric' => 'used_pct', 'op' => 'gt', 'threshold' => 80, 'level' => 'warning', 'created_at' => $now],
            ['name' => 'Disk 90% full', 'metric' => 'used_pct', 'op' => 'gt', 'threshold' => 90, 'level' => 'error', 'created_at' => $now],
            ['name' => 'Slow responses', 'metric' => 'latency_ms', 'op' => 'gt', 'threshold' => 2000, 'level' => 'warning', 'created_at' => $now],
            ['name' => 'Slow writes', 'metric' => 'write_mbps', 'op' => 'lt', 'threshold' => 5, 'level' => 'warning', 'created_at' => $now],
        ]);
        foreach ([['storage.view', 'View storage'], ['storage.manage', 'Manage storage']] as [$name, $label]) {
            if (! $this->db->table('permissions')->where('name', $name)->countAllResults()) {
                $this->db->table('permissions')->insert(['name' => $name, 'label' => $label, 'module' => 'storage', 'created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (['storage_alerts', 'storage_alert_rules', 'storage_allocations', 'storage_checks', 'storage_nodes'] as $t) $this->forge->dropTable($t, true);
        $this->db->table('permissions')->whereIn('name', ['storage.view', 'storage.manage'])->delete();
    }
}
