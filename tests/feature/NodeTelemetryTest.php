<?php

namespace Tests\Feature;

use App\Services\NodeTelemetryService;
use App\Services\ProviderService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** Node utilization reports, availability, and the OpenAI-compatible inference engines. */
final class NodeTelemetryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['servers', 'server_metrics'] as $t) $f->dropTable($t, true);
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true];
        $cols = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        foreach (['name', 'gpu_model', 'accelerator', 'cuda_version', 'rocm_version', 'metrics_at'] as $c) $cols[$c] = $null();
        foreach (['gpu_util_pct', 'vram_used_gb', 'cpu_util_pct', 'ram_used_gb', 'disk_used_gb', 'gpu_temp_c', 'gpu_vram_gb', 'ram_gb', 'storage_gb', 'cpu_cores'] as $c) $cols[$c] = $null('REAL');
        $f->addField($cols)->addKey('id', true)->createTable('servers');
        $m = ['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'server_id' => ['type' => 'INTEGER'], 'recorded_at' => ['type' => 'DATETIME']];
        foreach (['gpu_util_pct', 'vram_used_gb', 'cpu_util_pct', 'ram_used_gb', 'disk_used_gb', 'gpu_temp_c'] as $c) $m[$c] = $null('REAL');
        $f->addField($m)->addKey('id', true)->createTable('server_metrics');
        $this->db->table('servers')->insert(['name' => 'gpu-1', 'ram_gb' => 64]);
    }

    public function testRecordStoresLatestValuesAndOneSampleAMinute(): void
    {
        $svc    = new NodeTelemetryService();
        $server = $this->db->table('servers')->where('id', 1)->get()->getRowArray();
        $svc->record($server, ['gpu_util' => '87', 'vram_used_mb' => '20480', 'vram_total_mb' => '24576', 'cpu_util' => '12.5',
            'ram_used_mb' => '8192', 'ram_total_mb' => '131072', 'gpu_temp' => '71', 'accelerator' => 'cuda', 'gpu_name' => 'NVIDIA RTX 4090',
            'cuda_version' => '12.4', 'cpu_cores' => '32']);
        $row = $this->db->table('servers')->where('id', 1)->get()->getRowArray();
        $this->assertEquals(87.0, (float) $row['gpu_util_pct']);
        $this->assertEquals(20.0, (float) $row['vram_used_gb']);
        $this->assertEquals(24.0, (float) $row['gpu_vram_gb']);
        $this->assertEquals(64.0, (float) $row['ram_gb'], 'an admin-entered value is not overwritten');
        $this->assertSame('cuda', $row['accelerator']);
        $this->assertSame('NVIDIA RTX 4090', $row['gpu_model']);
        $this->assertEquals(32, (int) $row['cpu_cores']);

        $svc->record($row, ['gpu_util' => '50']);  // within the minute: latest updated, no second sample
        $this->assertSame(1, $this->db->table('server_metrics')->countAllResults());
        $this->assertEquals(50.0, (float) $this->db->table('servers')->where('id', 1)->get()->getRowArray()['gpu_util_pct']);
        $this->assertCount(1, $svc->history(1));
    }

    public function testOutOfRangeOrJunkValuesAreIgnored(): void
    {
        $server = $this->db->table('servers')->where('id', 1)->get()->getRowArray();
        (new NodeTelemetryService())->record($server, ['gpu_util' => '250', 'cpu_util' => 'abc', 'accelerator' => 'quantum']);
        $row = $this->db->table('servers')->where('id', 1)->get()->getRowArray();
        $this->assertNull($row['gpu_util_pct']);
        $this->assertNull($row['accelerator']);
        $this->assertSame(0, $this->db->table('server_metrics')->countAllResults());
    }

    public function testAvailability(): void
    {
        $now = date('Y-m-d H:i:s');
        $a = static fn (array $s) => NodeTelemetryService::availability($s);
        $this->assertSame('disabled', $a(['is_enabled' => 0, 'status' => 'online']));
        $this->assertSame('offline', $a(['status' => 'offline']));
        $this->assertSame('available', $a(['status' => 'online', 'gpu_util_pct' => 20, 'metrics_at' => $now]));
        $this->assertSame('busy', $a(['status' => 'online', 'gpu_util_pct' => 95, 'metrics_at' => $now]));
        $this->assertSame('busy', $a(['status' => 'online', 'gpu_vram_gb' => 24, 'vram_used_gb' => 23.5, 'metrics_at' => $now]));
        $this->assertSame('available', $a(['status' => 'online', 'gpu_util_pct' => 95, 'metrics_at' => date('Y-m-d H:i:s', time() - 3600)]), 'a stale report does not count');
    }

    public function testOpenAiCompatibleEngines(): void
    {
        $p = new ProviderService();
        $this->assertTrue($p->isOllama('ollama'));
        foreach (['vllm', 'llamacpp', 'openai_compatible', 'openai'] as $k) $this->assertFalse($p->isOllama($k));
        $this->assertFalse($p->isExternal('vllm'), 'a self-hosted engine is not a cloud API');
        $base = (new \ReflectionMethod($p, 'base'));
        $this->assertSame('http://10.0.0.5:8000/v1', $base->invoke($p, ['kind' => 'vllm', 'api_url' => 'http://10.0.0.5:8000/']));
        $this->assertSame('http://10.0.0.5:8080/v1', $base->invoke($p, ['kind' => 'llamacpp', 'api_url' => 'http://10.0.0.5:8080/v1']));
        $this->assertSame('http://10.0.0.5:11434', $base->invoke($p, ['kind' => 'ollama', 'api_url' => 'http://10.0.0.5:11434']));
    }
}
