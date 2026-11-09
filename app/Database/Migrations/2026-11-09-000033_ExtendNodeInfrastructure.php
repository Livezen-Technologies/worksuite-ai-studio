<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AI infrastructure: inference engines beyond Ollama (vLLM, llama.cpp, any OpenAI-compatible server),
 * node deployment type (local / remote / cloud / Docker), accelerator (CUDA / ROCm / CPU), live
 * utilization reported by the node agent, and a short metrics history.
 */
class ExtendNodeInfrastructure extends Migration
{
    public function up(): void
    {
        if ($this->db->DBDriver === 'MySQLi') { // ENUM('ollama','openai','anthropic','gemini') → open list
            $this->forge->modifyColumn('servers', ['kind' => ['name' => 'kind', 'type' => 'VARCHAR', 'constraint' => 24, 'default' => 'ollama', 'null' => false]]);
        }
        $dec = static fn (string $c = '6,1') => ['type' => 'DECIMAL', 'constraint' => $c, 'null' => true];
        $this->forge->addColumn('servers', [
            'deployment'      => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],   // local | remote | cloud | docker
            'accelerator'     => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],    // cuda | rocm | cpu
            'rocm_version'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'cloud_provider'  => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'region'          => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'container_image' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'max_concurrency' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'gpu_util_pct'    => $dec('5,1'),
            'vram_used_gb'    => $dec(),
            'cpu_util_pct'    => $dec('5,1'),
            'ram_used_gb'     => $dec(),
            'disk_used_gb'    => $dec('8,1'),
            'gpu_temp_c'      => $dec('5,1'),
            'metrics_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'server_id'    => ['type' => 'INT', 'unsigned' => true],
            'gpu_util_pct' => $dec('5,1'),
            'vram_used_gb' => $dec(),
            'cpu_util_pct' => $dec('5,1'),
            'ram_used_gb'  => $dec(),
            'disk_used_gb' => $dec('8,1'),
            'gpu_temp_c'   => $dec('5,1'),
            'recorded_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['server_id', 'recorded_at']);
        $this->forge->createTable('server_metrics', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('server_metrics', true);
        $this->forge->dropColumn('servers', ['deployment', 'accelerator', 'rocm_version', 'cloud_provider', 'region', 'container_image',
            'max_concurrency', 'gpu_util_pct', 'vram_used_gb', 'cpu_util_pct', 'ram_used_gb', 'disk_used_gb', 'gpu_temp_c', 'metrics_at']);
    }
}
