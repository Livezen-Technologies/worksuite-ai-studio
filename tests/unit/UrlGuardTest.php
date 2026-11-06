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

    public function testPinLeavesLiteralIpsAlone(): void
    {
        $this->assertSame([], UrlGuard::pin('http://10.0.0.5:9000/bucket'));
    }

    public function testPinPinsLocalhostToVettedAddress(): void
    {
        $entry = UrlGuard::pin('https://localhost/hook')[0] ?? '';
        $this->assertMatchesRegularExpression('/^localhost:443:/', $entry);
    }

    public function testPinRefusesBlockedAndUnresolvable(): void
    {
        foreach (['http://169.254.169.254/', 'http://metadata.google.internal/', 'http://no-such-host.invalid/', 'file:///etc/passwd'] as $url) {
            try { UrlGuard::pin($url); $this->fail("{$url} was pinned"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testS3DriverRefusesMetadataEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \App\Libraries\Storage\StorageDriver::for(['kind' => 's3'], ['endpoint' => 'http://169.254.169.254', 'bucket' => 'bucket'], ['access_key' => 'a', 'secret_key' => 'b']);
    }
}
