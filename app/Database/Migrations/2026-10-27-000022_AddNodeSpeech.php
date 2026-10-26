<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * GPU speech on nodes: a node's training agent runs the speech service on its GPU and forwards it to the Studio on
 * its tunnel port + 1000. The Speech API sends transcriptions to a healthy GPU node and falls back to this server.
 */
class AddNodeSpeech extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('servers', [
            'asr_port'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'asr_seen_at' => ['type' => 'DATETIME', 'null' => true],
            'asr_device'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'asr_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('servers', ['asr_port', 'asr_seen_at', 'asr_device', 'asr_enabled']);
    }
}
