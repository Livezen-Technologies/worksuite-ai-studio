<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API key management v2: key types (personal / application / organization / service account),
 * revocation, rotation policy, short-lived access-token versioning, and the fine-grained
 * apikeys.* permissions granted to every role that works with the gateway.
 */
class ExtendApiKeys extends Migration
{
    /** slug => [label, roles granted] (admin roles bypass checks; granted for the Roles UI). */
    public const GRANTS = [
        'apikeys.view'   => ['View API keys', ['super_admin', 'admin', 'developer', 'customer']],
        'apikeys.manage' => ['Manage own API keys', ['super_admin', 'admin', 'developer', 'customer']],
        'apikeys.tokens' => ['Issue API access tokens', ['super_admin', 'admin', 'developer', 'customer']],
        'apikeys.admin'  => ['Manage all API keys (organization / service accounts)', ['super_admin', 'admin']],
    ];

    public function up(): void
    {
        $cols = [
            'key_type'       => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'personal', 'null' => false],
            'application'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'organization'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'description'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'rotation_days'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rotated_at'     => ['type' => 'DATETIME', 'null' => true],
            'revoked_at'     => ['type' => 'DATETIME', 'null' => true],
            'token_version'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'null' => false],
            // set when an admin edits someone else's key: the owner may then only rename or disable it
            'admin_locked'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
        ];
        // Only missing columns: a run that died partway can be repeated (MySQL DDL is not transactional).
        $cols = array_filter($cols, fn ($c, $name) => ! $this->db->fieldExists($name, 'api_keys'), ARRAY_FILTER_USE_BOTH);
        if ($cols) $this->forge->addColumn('api_keys', $cols);
        if ($this->db->DBDriver === 'MySQLi') { // allow revoked / expired
            $this->forge->modifyColumn('api_keys', ['status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active']]);
        }

        $now = date('Y-m-d H:i:s');
        foreach (self::GRANTS as $slug => [$label, $roles]) {
            $row = $this->db->table('permissions')->where('name', $slug)->get()->getRowArray();
            if ($row) {
                $pid = (int) $row['id'];
            } else {
                $this->db->table('permissions')->insert(['name' => $slug, 'label' => $label, 'module' => 'apikeys', 'created_at' => $now]);
                $pid = (int) $this->db->insertID();
            }
            foreach ($this->db->table('roles')->whereIn('name', $roles)->get()->getResultArray() as $role) {
                if (! $this->db->table('role_permissions')->where(['role_id' => $role['id'], 'permission_id' => $pid])->countAllResults()) {
                    $this->db->table('role_permissions')->insert(['role_id' => $role['id'], 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = array_column($this->db->table('permissions')->whereIn('name', ['apikeys.tokens', 'apikeys.admin'])->get()->getResultArray(), 'id');
        if ($ids) {
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            $this->db->table('permissions')->whereIn('id', $ids)->delete();
        }
        $this->forge->dropColumn('api_keys', ['key_type', 'application', 'organization', 'description', 'rotation_days', 'rotated_at', 'revoked_at', 'token_version', 'admin_locked']);
    }
}
