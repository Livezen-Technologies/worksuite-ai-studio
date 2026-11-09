<?php

namespace Tests\Unit;

use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;

/** The daily cache job removes expired entries only; live rate-limit counters survive (audit B12). */
final class CachePruneTest extends CIUnitTestCase
{
    public function testExpiredGoneLiveKept(): void
    {
        $cfg = new \Config\Cache();
        $cfg->file['storePath'] = sys_get_temp_dir() . '/aistudio-cache-' . getmypid() . '/';
        @mkdir($cfg->file['storePath']);
        $cache = new FileHandler($cfg);
        $cache->initialize();
        $cache->save('gwfail_live', 3, 300);
        $cache->save('old_entry', 'x', 1);
        sleep(2);
        $svc = (new \ReflectionClass(\App\Services\SchedulerService::class))->newInstanceWithoutConstructor();
        $out = $svc->pruneCache($cache);
        $this->assertSame(1, $out['pruned']);
        $this->assertSame(3, $cache->get('gwfail_live'));
        $cache->clean();
        @rmdir($cfg->file['storePath']);
    }
}
