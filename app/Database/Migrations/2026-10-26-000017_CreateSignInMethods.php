<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extra sign-in methods: TOTP two-factor (secret encrypted at rest, see TotpService), one-time recovery
 * codes (SHA-256 hashed), WebAuthn passkeys, and `two_factor_required` so an admin can force 2FA per user.
 */
class CreateSignInMethods extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'totp_secret'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'password_hash'],
            'totp_enabled_at'     => ['type' => 'DATETIME', 'null' => true, 'after' => 'totp_secret'],
            'totp_last_step'      => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'totp_enabled_at'],
            'two_factor_required' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'totp_last_step'],
        ]);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'code_hash'  => ['type' => 'CHAR', 'constraint' => 64],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->createTable('user_recovery_codes');

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'INT', 'unsigned' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 120],
            'credential_id' => ['type' => 'VARCHAR', 'constraint' => 255],
            'public_key'    => ['type' => 'TEXT'],
            'alg'           => ['type' => 'INT'],
            'sign_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'transports'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'last_used_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->addUniqueKey('credential_id');
        $this->forge->createTable('user_passkeys');
    }

    public function down(): void
    {
        $this->forge->dropTable('user_passkeys');
        $this->forge->dropTable('user_recovery_codes');
        $this->forge->dropColumn('users', ['totp_secret', 'totp_enabled_at', 'totp_last_step', 'two_factor_required']);
    }
}
