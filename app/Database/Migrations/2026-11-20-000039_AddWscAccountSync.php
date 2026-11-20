<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Staff SSO part 2 (admin account sync): the WorkSuite Cloud subject on the local user, whether the
 * account is managed there, whether WorkSuite Cloud (not a local admin) disabled it, and the seen
 * provisioning-event jtis (kept until they expire) so an event can't be replayed.
 */
class AddWscAccountSync extends Migration
{
    public function up(): void
    {
        $cols = [
            'wsc_sub'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'managed_by_wsc' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'wsc_disabled'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ];
        $cols = array_filter($cols, fn ($c, $name) => ! $this->db->fieldExists($name, 'users'), ARRAY_FILTER_USE_BOTH);
        if ($cols) $this->forge->addColumn('users', $cols);
        if (isset($cols['wsc_sub'])) $this->forge->addKey('wsc_sub', false, true, 'users_wsc_sub_unique')->processIndexes('users');

        if (! $this->db->tableExists('wsc_seen_jti')) {
            $this->forge->addField([
                'jti'        => ['type' => 'VARCHAR', 'constraint' => 191],
                'expires_at' => ['type' => 'DATETIME'],
            ])->addKey('jti', true)->addKey('expires_at')->createTable('wsc_seen_jti');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('wsc_seen_jti', true);
        $this->forge->dropKey('users', 'users_wsc_sub_unique', false);
        $this->forge->dropColumn('users', ['wsc_sub', 'managed_by_wsc', 'wsc_disabled']);
    }
}
