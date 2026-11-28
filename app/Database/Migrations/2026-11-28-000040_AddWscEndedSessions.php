<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SSO Phase B (back-channel logout): AI Studio sessions are stateless tokens, so a logout from
 * WorkSuite Cloud is recorded here and checked on refresh and on every API request. A row ends either
 * the sessions of one OneAuth `sid`, or (sid NULL) every OneAuth-made session of `user_id` issued up to
 * `ended_at`. Password and passkey sessions are never matched. Rows are kept until `expires_at` (the
 * refresh-token lifetime), then pruned.
 */
class AddWscEndedSessions extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('wsc_ended_sessions')) return;
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'sid'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ended_at'   => ['type' => 'DATETIME'],
            'expires_at' => ['type' => 'DATETIME'],
        ])->addKey('id', true)->addKey('sid')->addKey('user_id')->addKey('expires_at')->createTable('wsc_ended_sessions');
    }

    public function down(): void
    {
        $this->forge->dropTable('wsc_ended_sessions', true);
    }
}
