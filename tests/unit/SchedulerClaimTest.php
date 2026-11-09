<?php

namespace Tests\Unit;

use App\Services\SchedulerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** Audit B9: a scheduled job is never started twice at once. */
final class SchedulerClaimTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $f = \Config\Database::forge($this->DBGroup);
        $f->dropTable('cron_jobs', true);
        $n = static fn (string $t = 'VARCHAR') => ['type' => $t, 'null' => true] + ($t === 'VARCHAR' ? ['constraint' => 191] : []);
        $f->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'key' => $n(), 'name' => $n(), 'description' => $n(), 'handler' => $n(), 'schedule' => $n(),
            'enabled' => ['type' => 'INTEGER', 'default' => 1], 'last_run_at' => $n('DATETIME'), 'last_status' => $n(), 'last_output' => $n(),
            'next_run_at' => $n('DATETIME'), 'created_at' => $n('DATETIME'), 'updated_at' => $n('DATETIME')])->addKey('id', true)->createTable('cron_jobs');
    }

    public function testRunningJobIsNotStartedAgain(): void
    {
        $past = date('Y-m-d H:i:s', time() - 60);
        // cleanup_cache runs inline; one copy is mid-run (claimed 2 minutes ago, longer than its interval)
        $this->db->table('cron_jobs')->insertBatch([
            ['key' => 'busy', 'handler' => 'cleanup_cache', 'schedule' => 'every_minute', 'next_run_at' => $past, 'last_status' => 'running', 'updated_at' => date('Y-m-d H:i:s', time() - 120)],
            ['key' => 'due', 'handler' => 'cleanup_cache', 'schedule' => 'every_minute', 'next_run_at' => $past, 'last_status' => 'ok', 'updated_at' => $past],
            ['key' => 'dead', 'handler' => 'cleanup_cache', 'schedule' => 'every_minute', 'next_run_at' => $past, 'last_status' => 'running', 'updated_at' => date('Y-m-d H:i:s', time() - 7200)],
        ]);
        $out = (new SchedulerService())->runDue();
        $this->assertSame(['due', 'dead'], array_keys($out), 'a run started under an hour ago is left alone; an older "running" is presumed dead');
        $this->assertSame([], (new SchedulerService())->runDue(), 'nothing is due again straight away');
    }
}
