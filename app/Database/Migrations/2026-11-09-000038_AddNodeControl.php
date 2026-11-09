<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Node controls (restart Ollama, update Ollama, shut down): the Studio records the request on the
 * server row; the node agent picks it up with its next heartbeat, runs it and reports the result.
 */
class AddNodeControl extends Migration
{
    public function up(): void
    {
        $cols = [
            'pending_control'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'pending_control_at' => ['type' => 'DATETIME', 'null' => true],
            'control_result'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'control_result_at'  => ['type' => 'DATETIME', 'null' => true],
        ];
        $cols = array_filter($cols, fn ($c, $name) => ! $this->db->fieldExists($name, 'servers'), ARRAY_FILTER_USE_BOTH);
        if ($cols) $this->forge->addColumn('servers', $cols);
    }

    public function down(): void
    {
        $this->forge->dropColumn('servers', ['pending_control', 'pending_control_at', 'control_result', 'control_result_at']);
    }
}
