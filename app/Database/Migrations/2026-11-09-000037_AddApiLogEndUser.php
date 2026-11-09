<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** api_logs.end_user: OpenAI's `user` field (the calling app's id for its end user), for abuse tracing. */
class AddApiLogEndUser extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('end_user', 'api_logs')) {
            $this->forge->addColumn('api_logs', ['end_user' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true]]);
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('api_logs', 'end_user');
    }
}
