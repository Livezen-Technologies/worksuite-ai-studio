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
        foreach (['roles', 'users', 'role_permissions', 'permissions', 'audit_logs', 'wsc_seen_jti'] as $t) $forge->dropTable($t, true);
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
            'wsc_sub' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true], 'managed_by_wsc' => ['type' => 'INTEGER', 'default' => 0],
            'wsc_disabled' => ['type' => 'INTEGER', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addKey('id', true)->createTable('users');
        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true],
            'user_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true], 'action' => ['type' => 'VARCHAR', 'constraint' => 120],
            'method' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true], 'path' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'status' => ['type' => 'INTEGER', 'null' => true], 'ip' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'meta' => ['type' => 'TEXT', 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addKey('id', true)->createTable('audit_logs');
        $forge->addField(['jti' => ['type' => 'VARCHAR', 'constraint' => 191], 'expires_at' => ['type' => 'DATETIME']])->addKey('jti', true)->createTable('wsc_seen_jti');
        $this->db->table('roles')->insertBatch([['name' => 'super_admin', 'label' => 'Super admin'], ['name' => 'viewer', 'label' => 'Viewer'],
            ['name' => 'admin', 'label' => 'Admin'], ['name' => 'customer', 'label' => 'Customer']]);
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
            'email_verified' => true, 'wsc_staff' => true, 'wsc_app' => 'aistudio', 'wsc_app_role' => 'super_admin', 'name' => 'Admin', 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 300];
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

    private function user(string $email): ?object
    {
        return $this->db->table('users')->where('email', $email)->get()->getRow();
    }

    public function testFirstSignInCreatesTheUserWithTheGrantedRole(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['email' => 'New.Person@Example.com', 'sub' => 'staff:9', 'name' => 'New Person', 'wsc_app_role' => 'admin']);
        $this->assertSame('ok', $this->location($this->returnFromIdp($state))['wsc'] ?? null);
        $u = $this->user('new.person@example.com');
        $this->assertSame(['New Person', 3, 'staff:9', 1, 'active'], [$u->name, (int) $u->role_id, $u->wsc_sub, (int) $u->managed_by_wsc, $u->status]);
    }

    public function testExistingUserIsLinkedByEmailAndGetsTheClaimedRole(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['email' => 'VIEWER@example.com', 'sub' => 'staff:4', 'wsc_app_role' => 'admin', 'name' => 'Viewer']);
        $this->assertSame('ok', $this->location($this->returnFromIdp($state))['wsc'] ?? null);
        $u = $this->user('viewer@example.com');
        $this->assertSame([2, 'staff:4', 3, 1], [(int) $u->id, $u->wsc_sub, (int) $u->role_id, (int) $u->token_version]);
        $this->assertSame(2, $this->db->table('users')->countAllResults());
    }

    public function testSignInWithoutAnAppRoleIsRefused(): void
    {
        foreach ([['wsc_app_role' => null], ['wsc_app_role' => 'customer'], ['wsc_app_role' => 'root']] as $bad) {
            [$state, $nonce] = $this->begin();
            $this->staff($nonce, $bad);
            $q = $this->location($this->returnFromIdp($state));
            $this->assertStringContainsString("don't have access to AI Studio", $q['wsc_error'], json_encode($bad));
            $this->assertNull($this->user('admin@example.com')->wsc_sub);
        }
    }

    public function testLocallySuspendedUserIsNotSignedIn(): void
    {
        $this->db->table('users')->where('id', 2)->update(['status' => 'suspended']);
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['email' => 'viewer@example.com', 'sub' => 'staff:4']);
        $this->assertStringContainsString('suspended', $this->location($this->returnFromIdp($state))['wsc_error']);
    }

    public function testSilentAccessDeniedShowsAMessage(): void
    {
        $res = $this->get('api/auth/wsc/start?silent=1');
        parse_str(parse_url($res->response()->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        service('superglobals')->setCookie(WscAuthController::FLOW_COOKIE, $res->response()->getCookie(WscAuthController::FLOW_COOKIE)->getValue());
        $back = $this->location($this->get('api/auth/wsc/callback?error=access_denied&state=' . $q['state']));
        service('superglobals')->unsetCookie(WscAuthController::FLOW_COOKIE);
        $this->assertSame('1', $back['wsc_denied']);
    }

    // --- provisioning push ---------------------------------------------------------------------

    private function event(array $over = []): string
    {
        return $this->sign($over + ['iss' => self::ISS, 'aud' => 'aistudio', 'iat' => time(), 'exp' => time() + 300,
            'jti' => bin2hex(random_bytes(8)), 'event' => 'upsert', 'sub' => 'staff:7', 'email' => 'Ops@Example.com', 'name' => 'Ops', 'role' => 'viewer']);
    }

    private function provision(string $jwt, string $type = 'application/jwt')
    {
        return $this->withHeaders(['Content-Type' => $type])->withBody($jwt)->post('api/auth/wsc/provision');
    }

    public function testProvisionUpsertCreatesThenUpdates(): void
    {
        $this->provision($this->event())->assertStatus(204);
        $u = $this->user('ops@example.com');
        $this->assertSame(['Ops', 2, 'staff:7'], [$u->name, (int) $u->role_id, $u->wsc_sub]);
        $this->provision($this->event(['name' => 'Ops Lead', 'role' => 'admin', 'email' => 'ops.lead@example.com']))->assertStatus(204);
        $u = $this->db->table('users')->where('id', $u->id)->get()->getRow();
        $this->assertSame(['Ops Lead', 3, 'ops.lead@example.com', 1], [$u->name, (int) $u->role_id, $u->email, (int) $u->token_version]);
    }

    public function testProvisionDisableSuspendsAndUpsertReenables(): void
    {
        $this->provision($this->event(['sub' => 'staff:3', 'email' => 'admin@example.com', 'role' => 'super_admin']))->assertStatus(204);
        $this->provision($this->event(['event' => 'disable', 'sub' => 'staff:3', 'role' => null]))->assertStatus(204);
        $u = $this->user('admin@example.com');
        $this->assertSame(['suspended', 1, 1], [$u->status, (int) $u->wsc_disabled, (int) $u->token_version]); // linking with the same role kept sessions; the disable ended them
        $this->provision($this->event(['sub' => 'staff:3', 'email' => 'admin@example.com', 'role' => 'super_admin']))->assertStatus(204);
        $this->assertSame('active', $this->user('admin@example.com')->status);
    }

    public function testProvisionKeepsALocalSuspensionAndIgnoresStrangers(): void
    {
        $this->db->table('users')->where('id', 2)->update(['status' => 'suspended']);
        $this->provision($this->event(['email' => 'viewer@example.com', 'sub' => 'staff:4']))->assertStatus(204);
        $this->assertSame('suspended', $this->user('viewer@example.com')->status);
        $this->provision($this->event(['event' => 'disable', 'sub' => 'staff:99', 'email' => 'nobody@example.com']))->assertStatus(204);
        $this->assertSame(['active', null], [$this->user('admin@example.com')->status, $this->user('admin@example.com')->wsc_sub]);
    }

    public function testProvisionRefusesBadTokens(): void
    {
        $this->provision($this->event(), 'application/json')->assertStatus(400);
        $this->provision('not-a-jwt')->assertStatus(400);
        $this->provision($this->event(['aud' => 'staff-desk']))->assertStatus(401);
        $this->provision($this->event(['exp' => time() - 3600, 'iat' => time() - 3900]))->assertStatus(401);
        $this->provision($this->event(['iss' => 'https://evil.test']))->assertStatus(401);
        $this->provision($this->event(['role' => 'customer']))->assertStatus(400);
        $this->signWith = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->provision($this->event())->assertStatus(401);
        $this->signWith = null;
        $this->assertNull($this->user('ops@example.com'));
    }

    public function testProvisionRefusesAReplayedJti(): void
    {
        $jwt = $this->event(['jti' => 'once']);
        $this->provision($jwt)->assertStatus(204);
        $this->provision($jwt)->assertStatus(401);
    }

    public function testManagedRoleCannotBeChangedLocallyButCanBeSuspended(): void
    {
        $this->db->table('users')->where('id', 2)->update(['managed_by_wsc' => 1, 'wsc_sub' => 'staff:4', 'wsc_disabled' => 1, 'status' => 'suspended']);
        $token = (new JwtService())->generateAccessToken(['id' => 1, 'email' => 'admin@example.com', 'name' => 'Admin', 'role' => 'x', 'token_version' => 0]);
        $auth = ['Authorization' => 'Bearer ' . $token];
        $this->withHeaders($auth)->withBodyFormat('json')->put('api/users/2', ['role_id' => 3])->assertStatus(422);
        $this->assertSame(2, (int) $this->user('viewer@example.com')->role_id);
        $this->withHeaders($auth)->withBodyFormat('json')->put('api/users/2', ['status' => 'active'])->assertStatus(200);
        $u = $this->user('viewer@example.com');
        $this->assertSame(['active', 0], [$u->status, (int) $u->wsc_disabled]);
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
