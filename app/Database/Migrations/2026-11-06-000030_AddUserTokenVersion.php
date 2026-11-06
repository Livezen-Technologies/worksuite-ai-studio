<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * users.token_version: stamped into every access/refresh token. Bumping it (sign out, password,
 * role or status change, sign-in reset) revokes all tokens issued before.
 */
class AddUserTokenVersion extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'token_version' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 0, 'null' => false],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'token_version');
    }
}
