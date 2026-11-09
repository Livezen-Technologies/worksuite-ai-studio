<?php

namespace Tests\Feature;

use App\Services\TrainingPipelineService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** Node controls travel with the agent heartbeat: handed out once, results recorded, stale ones expire. */
final class NodeControlTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['servers', 'storage_nodes', 'training_jobs'] as $t) $f->dropTable($t, true);
        $n = static fn (string $t = 'VARCHAR') => ['type' => $t, 'null' => true] + ($t === 'VARCHAR' ? ['constraint' => 191] : []);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $f->addField($id + ['name' => $n(), 'gpu_count' => $n('INTEGER'), 'agent_seen_at' => $n('DATETIME'), 'agent_version' => $n(), 'agent_job_id' => $n('INTEGER'),
            'tunnel_port' => $n('INTEGER'), 'asr_enabled' => ['type' => 'INTEGER', 'default' => 1], 'is_enabled' => ['type' => 'INTEGER', 'default' => 1],
            'asr_port' => $n('INTEGER'), 'asr_seen_at' => $n('DATETIME'), 'asr_device' => $n(), 'allow_training' => ['type' => 'INTEGER', 'default' => 0],
            'pending_control' => $n(), 'pending_control_at' => $n('DATETIME'), 'control_result' => $n(), 'control_result_at' => $n('DATETIME')])->addKey('id', true)->createTable('servers');
        $f->addField($id + ['kind' => $n(), 'deleted_at' => $n('DATETIME'), 'status' => $n(), 'config' => $n('TEXT'), 'secret' => $n('TEXT'), 'last_error' => $n()])->addKey('id', true)->createTable('storage_nodes');
        $f->addField($id + ['status' => $n(), 'server_id' => $n('INTEGER')])->addKey('id', true)->createTable('training_jobs');
        $this->db->table('servers')->insert(['name' => 'gpu-1', 'pending_control' => 'restart', 'pending_control_at' => date('Y-m-d H:i:s')]);
    }

    private function beat(array $in = []): array
    {
        $server = $this->db->table('servers')->where('id', 1)->get()->getRowArray();
        return (new TrainingPipelineService())->heartbeat($server, $in + ['version' => '1.7.0']);
    }

    public function testControlIsHandedOutOnceAndTheResultRecorded(): void
    {
        $this->assertSame('restart', $this->beat()['control'] ?? null);
        $this->assertArrayNotHasKey('control', $this->beat(), 'handed out once');
        $this->beat(['control_result' => 'ok: Ollama restarted']);
        $this->assertSame('ok: Ollama restarted', $this->db->table('servers')->where('id', 1)->get()->getRow()->control_result);
    }

    public function testStaleControlExpires(): void
    {
        $this->db->table('servers')->update(['pending_control_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->assertArrayNotHasKey('control', $this->beat());
        $this->assertStringStartsWith('Expired', $this->db->table('servers')->where('id', 1)->get()->getRow()->control_result);
    }
}
