<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Node enrolment: one-time tokens that let the `aistudio-node` tool register a machine as an Ollama node.
 * An enrolled node keeps a reverse SSH tunnel to this server as the tunnel-only `nodetunnel` user; sshd
 * reads its key from `servers.ssh_public_key` (spark nodes:authorized-keys), so removing the server revokes it.
 */
class CreateNodeEnrolment extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'token_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'expires_at' => ['type' => 'DATETIME'],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
            'server_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->createTable('node_enrolments');

        $this->forge->addColumn('servers', [
            'tunnel_port'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'port'],
            'ssh_public_key'  => ['type' => 'TEXT', 'null' => true, 'after' => 'tunnel_port'],
            'ssh_fingerprint' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'ssh_public_key'],
            'enrolled_at'     => ['type' => 'DATETIME', 'null' => true, 'after' => 'ssh_fingerprint'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('servers', ['tunnel_port', 'ssh_public_key', 'ssh_fingerprint', 'enrolled_at']);
        $this->forge->dropTable('node_enrolments');
    }
}
