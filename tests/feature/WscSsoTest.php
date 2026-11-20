<?php

namespace Tests\Feature;

use App\Controllers\Api\WscAuthController;
use App\Services\JwtService;
use App\Services\WscOidcService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Sign in with WorkSuite Cloud: the callback against a fake IdP (discovery, JWKS, token endpoint).
 * Nothing leaves the process.
 */
final class WscSsoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const ISS = 'https://account.example.test';
    private static $key;
    /** Claims the fake token endpoint signs into the next id_token. */
    private array $claims = [];
    private $signWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $forge = \Config\Database::forge($this->DBGroup);
        foreach (['roles', 'users', 'role_permissions', 'permissions', 'audit_logs'] as $t) $forge->dropTable($t, true);
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'name' => ['type' => 'VARCHAR', 'constraint' => 64], 'label' => ['type' => 'VARCHAR', 'constraint' => 100]])
            ->addKey('id', true)->createTable('roles');
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'name' => ['type' => 'VARCHAR', 'constraint' => 120]])
            ->addKey('id', true)->createTable('permissions');
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'role_id' => ['type' => 'INTEGER'], 'permission_id' => ['type' => 'INTEGER']])
            ->addKey('id', true)->createTable('role_permissions');
        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true], 'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'email' => ['type' => 'VARCHAR', 'constraint' => 191], 'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'role_id' => ['type' => 'INTEGER', 'null' => true], 'avatar' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'last_login_at' => ['type' => 'DATETIME', 'null' => true], 'totp_secret' => ['type' => 'TEXT', 'null' => true],
            'totp_enabled_at' => ['type' => 'DATETIME', 'null' => true], 'totp_last_step' => ['type' => 'INTEGER', 'null' => true],
            'two_factor_required' => ['type' => 'INTEGER', 'default' => 0], 'token_version' => ['type' => 'INTEGER', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addKey('id', true)->createTable('users');
        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true],
            'user_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true], 'action' => ['type' => 'VARCHAR', 'constraint' => 120],
            'method' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true], 'path' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'status' => ['type' => 'INTEGER', 'null' => true], 'ip' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'meta' => ['type' => 'TEXT', 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addKey('id', true)->createTable('audit_logs');
        $this->db->table('roles')->insertBatch([['name' => 'super_admin', 'label' => 'Super admin'], ['name' => 'viewer', 'label' => 'Viewer']]);
        $this->db->table('users')->insert([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password_hash' => password_hash('correct horse', PASSWORD_DEFAULT),
            'role_id' => 1, 'status' => 'active', 'token_version' => 0,
        ]);
        $this->db->table('users')->insert([
            'name' => 'Viewer', 'email' => 'viewer@example.com', 'password_hash' => 'x', 'role_id' => 2, 'status' => 'active', 'token_version' => 0,
        ]);
        Services::injectMock('wscOidc', new WscOidcService([
            'issuer' => self::ISS, 'client_id' => 'aistudio', 'client_secret' => 's3cret', 'redirect_uri' => 'https://aistudio.example.test/api/auth/wsc/callback',
        ], fn (string $m, string $url, array $form = []) => $this->idp($m, $url, $form)));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('wscOidc');
        parent::tearDown();
    }

    private function idp(string $method, string $url, array $form): array
    {
        if ($url === self::ISS . '/.well-known/openid-configuration') {
            return [200, json_encode(['issuer' => self::ISS, 'authorization_endpoint' => self::ISS . '/oauth/authorize',
                'token_endpoint' => self::ISS . '/oauth/token', 'jwks_uri' => self::ISS . '/oauth/jwks'])];
        }
        if ($url === self::ISS . '/oauth/jwks') {
            $d = openssl_pkey_get_details(self::$key)['rsa'];
            return [200, json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'n' => WscOidcService::b64u($d['n']), 'e' => WscOidcService::b64u($d['e'])]]])];
        }
        if ($url === self::ISS . '/oauth/token' && $method === 'POST') {
            if ($form['code'] !== 'good-code' || $form['client_secret'] !== 's3cret' || ($form['code_verifier'] ?? '') === '') return [400, '{"error":"invalid_grant"}'];
            return [200, json_encode(['id_token' => $this->sign($this->claims)])];
        }
        return [404, ''];
    }

    private function sign(array $claims): string
    {
        $h = WscOidcService::b64u(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
        $b = WscOidcService::b64u(json_encode($claims));
        openssl_sign("{$h}.{$b}", $sig, $this->signWith ?? self::$key, OPENSSL_ALGO_SHA256);
        return "{$h}.{$b}." . WscOidcService::b64u($sig);
    }

    /** Start a flow and return [state, nonce]; the flow cookie is set on $_COOKIE for the callback. */
    private function begin(): array
    {
        $res = $this->get('api/auth/wsc/start?from=/models');
        $loc = $res->response()->getHeaderLine('Location');
        $this->assertStringStartsWith(self::ISS . '/oauth/authorize?', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $cookie = $res->response()->getCookie(WscAuthController::FLOW_COOKIE)->getValue();
        service('superglobals')->setCookie(WscAuthController::FLOW_COOKIE, $cookie);
        return [$q['state'], $q['nonce']];
    }

    private function staff(string $nonce, array $over = []): void
    {
        $this->claims = $over + ['iss' => self::ISS, 'aud' => 'aistudio', 'sub' => 'staff:3', 'email' => 'ADMIN@Example.com',
            'email_verified' => true, 'wsc_staff' => true, 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 300];
    }

    private function returnFromIdp(string $state)
    {
        $res = $this->get('api/auth/wsc/callback?code=good-code&state=' . rawurlencode($state));
        service('superglobals')->unsetCookie(WscAuthController::FLOW_COOKIE);
        return $res;
    }

    private function location($res): array
    {
        $loc = $res->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/login', $loc);
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        return $q;
    }

    public function testConfigReportsEnabled(): void
    {
        $this->get('api/auth/wsc/config')->assertJSONFragment(['data' => ['enabled' => true]]);
        $this->assertFalse((new WscOidcService(['issuer' => '', 'client_id' => '', 'client_secret' => '', 'redirect_uri' => '']))->enabled());
    }

    public function testGoodPathSignsInTheMatchingAdmin(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce);
        $res = $this->returnFromIdp($state);
        $this->assertSame(['wsc' => 'ok', 'from' => '/models'], $this->location($res));
        $refresh = $res->response()->getCookie(\App\Controllers\Api\AuthController::REFRESH_COOKIE)->getValue();
        $p = (new JwtService())->decode($refresh);
        $this->assertSame(['refresh', 1], [$p['type'], $p['sub']]);
        $this->assertNotNull($this->db->table('users')->where('id', 1)->get()->getRow()->last_login_at);
    }

    public function testWrongStateIsRefused(): void
    {
        [, $nonce] = $this->begin();
        $this->staff($nonce);
        $q = $this->location($this->returnFromIdp('not-the-state'));
        $this->assertStringContainsString('expired', $q['wsc_error']);
    }

    public function testMissingFlowCookieIsRefused(): void
    {
        [$state, $nonce] = $this->begin();
        service('superglobals')->unsetCookie(WscAuthController::FLOW_COOKIE);
        $this->staff($nonce);
        $this->assertArrayHasKey('wsc_error', $this->location($this->get('api/auth/wsc/callback?code=good-code&state=' . $state)));
    }

    public function testUnknownEmailGetsAClearError(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['email' => 'nobody@example.com']);
        $this->assertSame('No AI Studio admin account for nobody@example.com', $this->location($this->returnFromIdp($state))['wsc_error']);
        $this->assertSame(2, $this->db->table('users')->countAllResults()); // never creates users
    }

    public function testNonAdminUserIsNotMatched(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['email' => 'viewer@example.com']);
        $this->assertSame('No AI Studio admin account for viewer@example.com', $this->location($this->returnFromIdp($state))['wsc_error']);
    }

    public function testBadSignatureIsRefused(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce);
        $this->signWith = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $q = $this->location($this->returnFromIdp($state));
        $this->assertStringContainsString('signature', $q['wsc_error']);
        $this->assertArrayNotHasKey('wsc', $q);
    }

    public function testWrongNonceAudienceAndExpiryAreRefused(): void
    {
        foreach ([['nonce' => 'other'], ['aud' => 'someone-else'], ['exp' => time() - 3600], ['iss' => 'https://evil.test'], ['wsc_staff' => false]] as $bad) {
            [$state, $nonce] = $this->begin();
            $this->staff($nonce, $bad);
            $this->assertArrayHasKey('wsc_error', $this->location($this->returnFromIdp($state)), json_encode($bad));
        }
    }

    public function testSilentFailureFallsBackQuietly(): void
    {
        $res = $this->get('api/auth/wsc/start?silent=1');
        parse_str(parse_url($res->response()->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('none', $q['prompt']);
        service('superglobals')->setCookie(WscAuthController::FLOW_COOKIE, $res->response()->getCookie(WscAuthController::FLOW_COOKIE)->getValue());
        $back = $this->get('api/auth/wsc/callback?error=login_required&state=' . $q['state']);
        service('superglobals')->unsetCookie(WscAuthController::FLOW_COOKIE);
        $this->assertSame([], $this->location($back));
    }

    public function testSafePathRejectsOffsiteTargets(): void
    {
        $this->assertSame('/', WscAuthController::safePath('//evil.test/x'));
        $this->assertSame('/', WscAuthController::safePath('https://evil.test'));
        $this->assertSame('/models?x=1', WscAuthController::safePath('/models?x=1'));
    }
}
