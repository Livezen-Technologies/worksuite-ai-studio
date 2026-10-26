<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * User portal + compute allocations: admins grant users CPU/GPU capacity per node (`node_allocations`);
 * training jobs record the node(s) they are placed on with a status per node (`training_job_nodes`).
 * Datasets get an owner-facing visibility. Permissions portal.use (users) and compute.manage (admins).
 */
class CreateComputeAllocations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('servers', [
            'gpu_count'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'gpu_vram_gb'    => ['type' => 'DECIMAL', 'constraint' => '6,1', 'null' => true],
            'allow_training' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);
        $this->forge->addColumn('datasets', [
            'visibility' => ['type' => "ENUM('private','shared')", 'default' => 'private'],
        ]);
        $this->forge->addColumn('training_jobs', [
            'placement_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'unplaced'],
        ]);

        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'             => ['type' => 'INT', 'unsigned' => true],
            'server_id'           => ['type' => 'INT', 'unsigned' => true],
            'max_cpu_cores'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_gpus'            => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_concurrent_jobs' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'expires_at'          => ['type' => 'DATETIME', 'null' => true],
            'notes'               => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'server_id']);
        $this->forge->addForeignKey('server_id', 'servers', 'id', '', 'CASCADE');
        $this->forge->createTable('node_allocations');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'training_job_id' => ['type' => 'INT', 'unsigned' => true],
            'server_id'       => ['type' => 'INT', 'unsigned' => true],
            'cpu_cores'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'gpus'            => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'          => ['type' => "ENUM('pending','assigned','running','completed','failed','cancelled')", 'default' => 'pending'],
            'progress'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'message'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'override'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'assigned_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'started_at'      => ['type' => 'DATETIME', 'null' => true],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['training_job_id', 'server_id']);
        $this->forge->addKey('server_id');
        $this->forge->addForeignKey('training_job_id', 'training_jobs', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('server_id', 'servers', 'id', '', 'CASCADE');
        $this->forge->createTable('training_job_nodes');

        // Permissions, idempotent. Admin roles bypass checks anyway; grant explicitly for the roles UI.
        $db  = $this->db;
        $now = date('Y-m-d H:i:s');
        $grants = [
            'portal.use'     => ['Use the training portal', ['super_admin', 'admin', 'developer', 'customer']],
            'compute.manage' => ['Manage compute allocations', ['super_admin', 'admin']],
        ];
        foreach ($grants as $slug => [$label, $roles]) {
            $row = $db->table('permissions')->where('name', $slug)->get()->getRowArray();
            if (! $row) {
                $db->table('permissions')->insert(['name' => $slug, 'label' => $label, 'module' => 'compute', 'created_at' => $now]);
                $pid = (int) $db->insertID();
            } else {
                $pid = (int) $row['id'];
            }
            foreach ($db->table('roles')->whereIn('name', $roles)->get()->getResultArray() as $role) {
                if (! $db->table('role_permissions')->where(['role_id' => $role['id'], 'permission_id' => $pid])->countAllResults()) {
                    $db->table('role_permissions')->insert(['role_id' => $role['id'], 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('training_job_nodes', true);
        $this->forge->dropTable('node_allocations', true);
        $this->forge->dropColumn('training_jobs', ['placement_status']);
        $this->forge->dropColumn('datasets', ['visibility']);
        $this->forge->dropColumn('servers', ['gpu_count', 'gpu_vram_gb', 'allow_training']);
        $ids = array_column($this->db->table('permissions')->whereIn('name', ['portal.use', 'compute.manage'])->get()->getResultArray(), 'id');
        if ($ids) {
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            $this->db->table('permissions')->whereIn('id', $ids)->delete();
        }
    }
}
