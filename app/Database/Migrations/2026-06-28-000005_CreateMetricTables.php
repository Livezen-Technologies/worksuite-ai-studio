<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMetricTables extends Migration
{
    public function up(): void
    {
        // system_metrics — periodic CPU/RAM/disk/net rollups per node (from Prometheus)
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'server_id'  => ['type' => 'INT', 'unsigned' => true],
            'cpu_pct'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'mem_pct'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'disk_pct'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'net_rx_kbps'=> ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'net_tx_kbps'=> ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'load_avg'   => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'recorded_at'=> ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['server_id', 'recorded_at']);
        $this->forge->createTable('system_metrics');

        // gpu_metrics — GPU utilisation/VRAM/temp/power rollups (NVIDIA exporter)
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'server_id'  => ['type' => 'INT', 'unsigned' => true],
            'gpu_index'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'util_pct'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'vram_used_mb' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'vram_total_mb'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'temp_c'     => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'power_w'    => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'fan_pct'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'recorded_at'=> ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['server_id', 'recorded_at']);
        $this->forge->createTable('gpu_metrics');

        // usage_statistics — daily rollup of requests/tokens (for analytics)
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'day'          => ['type' => 'DATE'],
            'model'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'requests'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'prompt_tokens'=> ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'completion_tokens' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'avg_ms'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'errors'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['day', 'model']);
        $this->forge->createTable('usage_statistics');
    }

    public function down(): void
    {
        $this->forge->dropTable('usage_statistics', true);
        $this->forge->dropTable('gpu_metrics', true);
        $this->forge->dropTable('system_metrics', true);
    }
}
