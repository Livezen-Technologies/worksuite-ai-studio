<?php

namespace Tests\Feature;

use App\Services\ModelRegistryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** Model registry: inference, discovery, placement, deployment states, access roles, defaults. */
final class ModelRegistryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        putenv('ASR_SERVICE_URL=http://127.0.0.1:1'); $_ENV['ASR_SERVICE_URL'] = $_SERVER['ASR_SERVICE_URL'] = 'http://127.0.0.1:1'; // speech service down
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['servers', 'models', 'model_registry', 'model_assignments', 'model_defaults', 'queues', 'users', 'roles', 'api_logs'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true];
        $f->addField($id + ['name' => $null(), 'kind' => $null(), 'api_url' => $null(), 'auth_token' => $null(), 'status' => $null(), 'gpu_vram_gb' => $null('REAL'),
            'vram_used_gb' => $null('REAL'), 'is_enabled' => ['type' => 'INTEGER', 'default' => 1], 'deleted_at' => $null('DATETIME'),
            'asr_enabled' => ['type' => 'INTEGER', 'default' => 1], 'asr_port' => $null('INTEGER'), 'asr_device' => $null(), 'asr_seen_at' => $null('DATETIME'),
            'gpu_model' => $null(), 'gpu_count' => $null('INTEGER')])->addKey('id', true)->createTable('servers');
        $f->addField($id + ['server_id' => $null('INTEGER'), 'name' => $null(), 'family' => $null(), 'parameter_size' => $null(), 'quantization' => $null(),
            'context_length' => $null('INTEGER'), 'size_bytes' => $null('INTEGER'), 'status' => $null()])->addKey('id', true)->createTable('models');
        $f->addField($id + ['name' => $null(), 'display_name' => $null(), 'type' => $null(), 'version' => $null(), 'family' => $null(), 'parameter_size' => $null(),
            'quantization' => $null(), 'vram_gb' => $null('REAL'), 'context_length' => $null('INTEGER'), 'capabilities' => $null('TEXT'), 'engine' => $null(),
            'source' => $null(), 'description' => $null(), 'access_roles' => $null('TEXT'), 'keep_loaded' => ['type' => 'INTEGER', 'default' => 0],
            'idle_unload_minutes' => $null('INTEGER'), 'enabled' => ['type' => 'INTEGER', 'default' => 1], 'created_by' => $null('INTEGER'),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_registry');
        $f->addField($id + ['registry_id' => $null('INTEGER'), 'server_id' => $null('INTEGER'), 'state' => $null(), 'job_id' => $null('INTEGER'), 'error' => $null(),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_assignments');
        $f->addField($id + ['task' => $null(), 'application' => ['type' => 'VARCHAR', 'default' => ''], 'model_name' => $null(), 'updated_at' => $null('DATETIME')])
            ->addKey('id', true)->createTable('model_defaults');
        $f->addField($id + ['type' => $null(), 'payload' => $null('TEXT'), 'status' => $null(), 'priority' => $null('INTEGER'), 'max_attempts' => $null('INTEGER'),
            'attempts' => ['type' => 'INTEGER', 'default' => 0], 'available_at' => $null('DATETIME'), 'created_at' => $null('DATETIME')])->addKey('id', true)->createTable('queues');
        $f->addField($id + ['name' => $null(), 'role_id' => $null('INTEGER')])->addKey('id', true)->createTable('users');
        $f->addField($id + ['name' => $null()])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['model' => $null(), 'server_id' => $null('INTEGER'), 'status' => $null('INTEGER'), 'processing_ms' => $null('INTEGER'),
            'total_tokens' => $null('INTEGER'), 'completion_tokens' => $null('INTEGER'), 'created_at' => $null('DATETIME')])->addKey('id', true)->createTable('api_logs');

        $this->db->table('servers')->insertBatch([
            ['name' => 'gpu-24', 'kind' => 'ollama', 'api_url' => 'http://x', 'status' => 'online', 'gpu_vram_gb' => 24],
            ['name' => 'gpu-8', 'kind' => 'ollama', 'api_url' => 'http://y', 'status' => 'online', 'gpu_vram_gb' => 8],
            ['name' => 'vllm', 'kind' => 'vllm', 'api_url' => 'http://z', 'status' => 'online', 'gpu_vram_gb' => 80],
            ['name' => 'openai', 'kind' => 'openai', 'api_url' => '', 'status' => 'online', 'gpu_vram_gb' => null],
        ]);
        $this->db->table('models')->insertBatch([
            ['server_id' => 1, 'name' => 'llama3.1:8b', 'family' => 'llama', 'size_bytes' => 4900000000, 'status' => 'running'],
            ['server_id' => 1, 'name' => 'nomic-embed-text', 'family' => 'nomic-bert', 'size_bytes' => 274000000, 'status' => 'available'],
        ]);
        $this->db->table('roles')->insertBatch([['name' => 'admin'], ['name' => 'customer'], ['name' => 'developer']]);
        $this->db->table('users')->insertBatch([['name' => 'C', 'role_id' => 2], ['name' => 'D', 'role_id' => 3]]);
    }

    public function testInfer(): void
    {
        $this->assertSame('embedding', ModelRegistryService::infer('nomic-embed-text')['type']);
        $this->assertSame('vision', ModelRegistryService::infer('llava:7b')['type']);
        $this->assertSame('stt', ModelRegistryService::infer('whisper-large-v3')['type']);
        $this->assertContains('code', ModelRegistryService::infer('qwen2.5-coder:7b')['capabilities']);
        $this->assertContains('reasoning', ModelRegistryService::infer('deepseek-r1:7b')['capabilities']);
    }

    public function testDiscoverRegistersInstalledModelsAsDeployed(): void
    {
        $svc = new ModelRegistryService();
        $this->assertSame(2 + count(\App\Services\SpeechService::BUILT_IN), $svc->discover(), 'installed models plus the built-in speech models');
        $this->assertSame(0, $svc->discover(), 'idempotent');
        $all = array_column($svc->all(), null, 'name');
        $this->assertSame('loaded', $all['llama3.1:8b']['status']);
        $this->assertSame('embedding', $all['nomic-embed-text']['type']);
        $this->assertSame('8b', $all['llama3.1:8b']['version']);
        $this->assertEqualsWithDelta(6.0, $all['llama3.1:8b']['vram_gb'], 0.2);
        $sp = $all['whisper:sinhala'];
        $this->assertSame(['stt', 'speech', 'Whisper Small · Sinhala'], [$sp['type'], $sp['engine'], $sp['display_name']]);
        $this->assertSame('AI Studio server', $sp['deployments'][0]['server']);
        $this->assertSame('failed', $sp['deployments'][0]['status'], 'the speech service is not answering');
    }

    public function testDeployRespectsVramAndEngineType(): void
    {
        $svc = new ModelRegistryService();
        $this->db->table('model_registry')->insert(['name' => 'qwen2.5:14b', 'vram_gb' => 11, 'enabled' => 1]);
        $m = $svc->findByName('qwen2.5:14b');
        $res = $svc->deploy($m, [1, 2, 3, 4]);
        $this->assertSame(['gpu-24'], $res['queued']);
        $this->assertSame(['vllm'], $res['recorded']);
        $this->assertArrayHasKey('gpu-8', $res['skipped']);
        $this->assertArrayHasKey('openai', $res['skipped']);
        $this->assertSame(1, $this->db->table('queues')->where('type', 'pull_model')->countAllResults());

        $dep = array_column($svc->deployments([(int) $m['id']])[(int) $m['id']], 'status', 'server');
        $this->assertSame(['gpu-24' => 'deploying', 'vllm' => 'pending'], $dep);

        $svc->pullFinished((int) $m['id'], 1, 'disk full');
        $dep = array_column($svc->deployments([(int) $m['id']])[(int) $m['id']], 'status', 'server');
        $this->assertSame('failed', $dep['gpu-24']);

        $this->assertSame(['gpu-8'], $svc->deploy($m, [2], true)['queued'], 'force overrides the VRAM check');
    }

    public function testAccessRolesAndDefaults(): void
    {
        $svc = new ModelRegistryService();
        $this->db->table('model_registry')->insertBatch([
            ['name' => 'secret-model', 'access_roles' => json_encode(['developer']), 'enabled' => 1],
            ['name' => 'off-model', 'access_roles' => null, 'enabled' => 0],
        ]);
        $this->assertTrue($svc->roleAllowed('unregistered', 'customer'));
        $this->assertTrue($svc->roleAllowed('secret-model', $svc->roleOf(2)));
        $this->assertFalse($svc->roleAllowed('secret-model', $svc->roleOf(1)));
        $this->assertTrue($svc->roleAllowed('secret-model', 'admin'));
        $this->assertFalse($svc->roleAllowed('off-model', 'admin'), 'a disabled model is closed to everyone');

        $svc->saveDefaults([
            ['task' => 'chat', 'model_name' => 'llama3.1:8b'],
            ['task' => 'chat', 'application' => 'HR portal', 'model_name' => 'qwen2.5:7b'],
            ['task' => 'nonsense', 'model_name' => 'x'],
        ]);
        $this->assertCount(2, $svc->defaults());
        $this->assertSame('qwen2.5:7b', $svc->defaultFor('chat', 'HR portal'));
        $this->assertSame('llama3.1:8b', $svc->defaultFor('chat', 'Other app'));
        $this->assertSame('llama3.1:8b', $svc->defaultFor('chat'));
        $this->assertNull($svc->defaultFor('embeddings'));
    }

    public function testPerformance(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('api_logs')->insertBatch([
            ['model' => 'm', 'status' => 200, 'processing_ms' => 1000, 'total_tokens' => 150, 'completion_tokens' => 100, 'created_at' => $now],
            ['model' => 'm', 'status' => 200, 'processing_ms' => 3000, 'total_tokens' => 350, 'completion_tokens' => 300, 'created_at' => $now],
            ['model' => 'm', 'status' => 502, 'processing_ms' => 0, 'total_tokens' => 0, 'completion_tokens' => null, 'created_at' => $now],
        ]);
        $p = (new ModelRegistryService())->performance(['m'])['m'];
        $this->assertSame(3, $p['requests']);
        $this->assertEqualsWithDelta(33.3, $p['error_rate'], 0.1);
        $this->assertSame(2000, $p['avg_ms']);
        $this->assertEqualsWithDelta(100.0, $p['tokens_per_sec'], 0.1);
    }

    public function testCatalogue(): void
    {
        $svc = new ModelRegistryService();
        $cat = array_column($svc->catalogue(), null, 'id');
        $this->assertSame(['gpu-24'], $cat['llama3.1:8b']['installed_on'], 'matched by its Ollama tag');
        $this->assertSame(['ollama', 'vllm'], $cat['llama3.1:8b']['engines']);
        $this->assertSame(['speech'], $cat['whisper:turbo']['engines']);
        foreach (['llm', 'embedding', 'vision', 'multimodal', 'stt'] as $t) $this->assertContains($t, array_column($cat, 'type'));

        $row = $svc->addFromCatalogue('qwen2.5:7b', 'vllm');
        $this->assertSame(['Qwen/Qwen2.5-7B-Instruct', 'vllm', 'llm'], [$row['name'], $row['engine'], $row['type']]);
        $this->assertNull($row['quantization'], 'a vLLM model runs the published weights');
        $this->assertSame((int) $row['id'], (int) $svc->addFromCatalogue('qwen2.5:7b', 'vllm')['id'], 'adding twice returns the same entry');
        $this->assertSame('qwen2.5:7b', $svc->addFromCatalogue('qwen2.5:7b', 'ollama')['name']);
        $this->assertTrue(array_column($svc->catalogue(), null, 'id')['qwen2.5:7b']['registered']);

        $sp = $svc->addFromCatalogue('whisper:medium', 'ollama');
        $this->assertSame(['whisper:medium', 'speech', 'stt'], [$sp['name'], $sp['engine'], $sp['type']], 'speech models always go to the speech service');

        $this->expectException(\InvalidArgumentException::class);
        $svc->addFromCatalogue('helalm:4b', 'ollama'); // only published as Hugging Face weights
    }
}
