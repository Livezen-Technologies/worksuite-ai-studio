<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WorkSuite Sinhala AI Engine — activation bindings (SRS FR ADM 003 / 004).
 *
 * One row per task × device profile (minimum, standard, apple, accelerated)
 * naming the active package and the previous one kept for roll back.
 * Packages already `active` are backfilled onto the `standard` profile, which
 * is the profile the task-wide transition → active and roll back act on.
 */
class CreateEngineBindings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'task'                => ['type' => 'VARCHAR', 'constraint' => 30],
            'profile'             => ['type' => 'VARCHAR', 'constraint' => 20],
            'active_package_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'previous_package_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['task', 'profile']);
        $this->forge->createTable('engine_bindings', true);

        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        // Backfill: newest active package per task → standard; an older active one (if any) → previous.
        $active = $db->table('engine_packages')->where('state', 'active')->orderBy('id', 'DESC')->get()->getResultArray();
        $byTask = [];
        foreach ($active as $p) $byTask[$p['task']][] = (int) $p['id'];
        foreach ($byTask as $task => $ids) {
            if ($db->table('engine_bindings')->where(['task' => $task, 'profile' => 'standard'])->countAllResults()) continue;
            $db->table('engine_bindings')->insert([
                'task' => $task, 'profile' => 'standard', 'active_package_id' => $ids[0],
                'previous_package_id' => $ids[1] ?? null, 'updated_at' => $now,
            ]);
        }

        // Permissions: 000013 creates them but grants them to no role. Idempotent.
        if ($db->tableExists('permissions')) {
            $permIds = [];
            foreach ([['engine.view', 'View Sinhala engine releases'], ['engine.manage', 'Manage engine evaluations/packages'], ['engine.sign', 'Sign and revoke engine packages']] as [$slug, $label]) {
                $row = $db->table('permissions')->where('name', $slug)->get()->getRowArray();
                if (! $row) {
                    $db->table('permissions')->insert(['name' => $slug, 'label' => $label, 'module' => 'engine', 'created_at' => $now]);
                    $permIds[] = (int) $db->insertID();
                } else {
                    $permIds[] = (int) $row['id'];
                }
            }
            if ($db->tableExists('roles') && $db->tableExists('role_permissions')) {
                foreach ($db->table('roles')->whereIn('name', ['super_admin', 'admin'])->get()->getResultArray() as $role) {
                    foreach ($permIds as $pid) {
                        if (! $db->table('role_permissions')->where(['role_id' => $role['id'], 'permission_id' => $pid])->countAllResults()) {
                            $db->table('role_permissions')->insert(['role_id' => $role['id'], 'permission_id' => $pid]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Grants and permissions are left in place; 000013's down() removes the permissions.
        $this->forge->dropTable('engine_bindings', true);
    }
}
