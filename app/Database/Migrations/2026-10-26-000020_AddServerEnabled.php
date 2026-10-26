<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A node can be disabled at any time from the Servers page: it then gets no new chat, gateway, knowledge-base or
 * training work. Health checks keep running so its state stays visible; jobs already running finish.
 */
class AddServerEnabled extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('servers', [
            'is_enabled'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'after' => 'status'],
            'disabled_at'     => ['type' => 'DATETIME', 'null' => true, 'after' => 'is_enabled'],
            'disabled_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'disabled_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('servers', ['is_enabled', 'disabled_at', 'disabled_reason']);
    }
}
