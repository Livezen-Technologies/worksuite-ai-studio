<?php

namespace App\Commands;

use App\Services\ApiGatewayService;
use App\Services\ModelRegistryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark gateway:selftest` — run the gateway's and the model registry's read queries against the
 * configured database, with a little sample data inside a rolled-back transaction. CI runs it on
 * MySQL 8 with the default sql_mode, where GROUP BY queries that SQLite accepts can fail.
 */
class GatewaySelftest extends BaseCommand
{
    protected $group       = 'WorkSuite AI Studio';
    protected $name        = 'gateway:selftest';
    protected $description = 'Run the gateway and model registry queries against the database (no lasting changes).';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $db->table('servers')->insert(['name' => 'selftest-a', 'api_url' => 'http://127.0.0.1:1', 'kind' => 'ollama', 'status' => 'online', 'created_at' => $now]);
            $a = (int) $db->insertID();
            $db->table('servers')->insert(['name' => 'selftest-b', 'api_url' => 'http://127.0.0.1:2', 'kind' => 'vllm', 'status' => 'offline', 'created_at' => $now]);
            $b = (int) $db->insertID();
            foreach ([[$a, 'q4_K_M'], [$b, null]] as [$sid, $q]) {
                $db->table('models')->insert(['server_id' => $sid, 'name' => 'selftest-model', 'quantization' => $q, 'size_bytes' => 1000, 'status' => 'available', 'created_at' => $now]);
            }
            $db->table('api_logs')->insert(['endpoint' => 'chat', 'model' => 'selftest-model', 'status' => 200, 'total_tokens' => 5, 'processing_ms' => 100, 'created_at' => $now]);

            $checks = [
                'gateway models'    => fn () => (new ApiGatewayService())->availableModels(),
                'gateway candidates' => fn () => (new ApiGatewayService())->candidates('selftest-model'),
                'registry discover' => fn () => (new ModelRegistryService())->discover(),
                'registry list'     => fn () => (new ModelRegistryService())->all(),
                'registry catalogue' => fn () => (new ModelRegistryService())->catalogue(),
                'registry perf'     => fn () => (new ModelRegistryService())->performance(['selftest-model']),
            ];
            foreach ($checks as $label => $fn) {
                $fn();
                CLI::write(" ok  {$label}", 'green');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            CLI::error(' FAIL ' . $e->getMessage());
            exit(1);
        }
        $db->transRollback();
        CLI::write('All gateway and registry queries ran.', 'green');
    }
}
