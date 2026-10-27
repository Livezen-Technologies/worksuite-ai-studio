<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Storage part 2 — files across nodes.
 *   storage_policies    how many replicas and how nodes are chosen (capacity | priority | round_robin), optional node tags
 *   storage_objects     one row per file: key, app, size, sha256, policy
 *   storage_placements  which node holds a copy of which object (ok | failed)
 *   storage_tasks       background work: replicate | migrate | delete | repair (pending | running | done | failed)
 */
class CreateStorageObjects extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'replicas'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 2],
            'strategy'   => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'capacity'],
            'node_tags'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'app'        => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],     // default policy for this app
            'is_default' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('storage_policies');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'        => ['type' => 'CHAR', 'constraint' => 36],
            'app'         => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'files'],
            'object_key'  => ['type' => 'VARCHAR', 'constraint' => 500],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'size'        => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'sha256'      => ['type' => 'CHAR', 'constraint' => 64],
            'mime'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'policy_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'owner_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['app', 'object_key'], false, false, 'app_key');
        $this->forge->createTable('storage_objects');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'object_id'   => ['type' => 'BIGINT', 'unsigned' => true],
            'node_id'     => ['type' => 'INT', 'unsigned' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ok'],
            'verified_at' => ['type' => 'DATETIME', 'null' => true],
            'error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['object_id', 'node_id']);
        $this->forge->addKey('node_id');
        $this->forge->createTable('storage_placements');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'type'        => ['type' => 'VARCHAR', 'constraint' => 20],
            'object_id'   => ['type' => 'BIGINT', 'unsigned' => true],
            'from_node'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'to_node'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'id']);
        $this->forge->createTable('storage_tasks');

        $now = date('Y-m-d H:i:s');
        $this->db->table('storage_policies')->insert(['name' => 'Default — 2 copies, most free space', 'replicas' => 2, 'strategy' => 'capacity', 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        foreach (['storage_tasks', 'storage_placements', 'storage_objects', 'storage_policies'] as $t) $this->forge->dropTable($t, true);
    }
}
