<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Storage part 3 — VPS nodes, backups, disaster recovery.
 *   storage_backups      scheduled database backups (daily | weekly), kept as files in the object store (app "backups")
 *   storage_backup_runs  one row per run: status, size, the stored object, error
 *   storage_policies.min_regions   copies must cover at least this many regions (off-site copies for DR)
 */
class CreateStorageBackups extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'schedule'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'daily'],
            'at_hour'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 2],       // server time
            'keep'           => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 14],
            'exclude_tables' => ['type' => 'TEXT', 'null' => true],                              // comma-separated
            'policy_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'enabled'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'last_run_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('storage_backups');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'backup_id'   => ['type' => 'INT', 'unsigned' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'running'],   // running | ok | failed
            'trigger'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'schedule'],
            'object_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'size'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'pid'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'started_at'  => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['backup_id', 'id']);
        $this->forge->createTable('storage_backup_runs');

        $this->forge->addColumn('storage_policies', ['min_regions' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'after' => 'replicas']]);

        $now = date('Y-m-d H:i:s');
        // off until an administrator turns it on (the server disk is nearly full; point it at a node with room)
        $this->db->table('storage_backups')->insert(['name' => 'Studio database', 'schedule' => 'daily', 'at_hour' => 2, 'keep' => 14, 'enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('storage_policies', 'min_regions');
        $this->forge->dropTable('storage_backup_runs', true);
        $this->forge->dropTable('storage_backups', true);
    }
}
