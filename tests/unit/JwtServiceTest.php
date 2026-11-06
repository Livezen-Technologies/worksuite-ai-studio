<?php

namespace Tests\Unit;

use App\Services\JwtService;
use CodeIgniter\Test\CIUnitTestCase;

final class JwtServiceTest extends CIUnitTestCase
{
    public function testAccessTokenRoundTripCarriesTokenVersion(): void
    {
        $jwt = new JwtService();
        $p = $jwt->decode($jwt->generateAccessToken(['id' => 7, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'admin', 'token_version' => 3]));
        $this->assertSame(7, $p['sub']);
        $this->assertSame('access', $p['type']);
        $this->assertSame(3, $p['tv']);
        $this->assertSame('admin', $p['role']);
    }

    public function testRefreshTokenCarriesTokenVersion(): void
    {
        $jwt = new JwtService();
        $p = $jwt->decode($jwt->generateRefreshToken(9, 4));
        $this->assertSame(['refresh', 9, 4], [$p['type'], $p['sub'], $p['tv']]);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $jwt = new JwtService();
        [$h, $b, $s] = explode('.', $jwt->generateAccessToken(['id' => 1, 'role' => 'viewer']));
        $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 1, 'role' => 'super_admin', 'type' => 'access', 'exp' => time() + 60])), '+/', '-_'), '=');
        $this->assertNull($jwt->decode("{$h}.{$forged}.{$s}"));
    }

    public function testTokenSignedWithTheDevDefaultIsRejected(): void
    {
        // A token forged with the secret that ships in the repository must not validate.
        $h = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"HS256"}'), '+/', '-_'), '=');
        $b = rtrim(strtr(base64_encode(json_encode(['sub' => 1, 'role' => 'super_admin', 'type' => 'access', 'exp' => time() + 60])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$b}", JwtService::DEV_SECRET, true)), '+/', '-_'), '=');
        $this->assertNull((new JwtService())->decode("{$h}.{$b}.{$sig}"));
    }

    public function testSecretComesFromEnvironment(): void
    {
        $this->assertSame('test-secret-for-phpunit-0123456789abcdef', JwtService::secret());
    }
}
