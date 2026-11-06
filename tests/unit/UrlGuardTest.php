<?php

namespace Tests\Unit;

use App\Services\UrlGuard;
use CodeIgniter\Test\CIUnitTestCase;

final class UrlGuardTest extends CIUnitTestCase
{
    /** @dataProvider allowed */
    public function testAllowsNodeAddresses(string $url): void
    {
        $this->assertNull(UrlGuard::reason($url));
    }

    public static function allowed(): array
    {
        return [['http://127.0.0.1:11434'], ['http://10.0.0.5:11434'], ['http://192.168.1.20'], ['https://203.0.113.10:8443'], ['http://[::1]:11434']];
    }

    /** @dataProvider blocked */
    public function testBlocksMetadataAndLinkLocal(string $url): void
    {
        $this->assertNotNull(UrlGuard::reason($url));
    }

    public static function blocked(): array
    {
        return [
            ['http://169.254.169.254/latest/meta-data/'], ['http://metadata.google.internal/'], ['http://0.0.0.0:11434'],
            ['http://[fe80::1]/'], ['http://[::ffff:169.254.169.254]/'], ['file:///etc/passwd'], ['gopher://127.0.0.1'],
            ['http://user:pw@10.0.0.1/'], ['http://100.100.100.200/'],
        ];
    }
}
