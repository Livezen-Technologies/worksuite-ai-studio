<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Key rotation without downtime and with enforcement: after a rotation the previous secret keeps
 * working until previous_secret_expires_at; rotation_enforce turns a key off when its rotation is
 * more than a week overdue; rotation_notified_at limits reminders to one a day.
 */
class AddKeyRotationGrace extends Migration
{
    public function up(): void
    {
        $cols = [
            'previous_secret_hash'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'previous_secret_expires_at' => ['type' => 'DATETIME', 'null' => true],
            'rotation_enforce'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            'rotation_notified_at'       => ['type' => 'DATETIME', 'null' => true],
        ];
        $cols = array_filter($cols, fn ($c, $name) => ! $this->db->fieldExists($name, 'api_keys'), ARRAY_FILTER_USE_BOTH);
        if ($cols) $this->forge->addColumn('api_keys', $cols);
    }

    public function down(): void
    {
        $this->forge->dropColumn('api_keys', ['previous_secret_hash', 'previous_secret_expires_at', 'rotation_enforce', 'rotation_notified_at']);
    }
}
