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
    /** A second issuer (Phase B: the auth issuer) on the same keys. */
    private const AUTH = 'https://auth.example.test';
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
        foreach (['roles', 'users', 'role_permissions', 'permissions', 'audit_logs', 'wsc_seen_jti', 'wsc_ended_sessions'] as $t) $forge->dropTable($t, true);
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
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sid' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'user_id' => ['type' => 'INTEGER', 'null' => true], 'ended_at' => ['type' => 'DATETIME'], 'expires_at' => ['type' => 'DATETIME']])
            ->addKey('id', true)->createTable('wsc_ended_sessions');
        $this->db->table('roles')->insertBatch([['name' => 'super_admin', 'label' => 'Super admin'], ['name' => 'viewer', 'label' => 'Viewer'],
            ['name' => 'admin', 'label' => 'Admin'], ['name' => 'customer', 'label' => 'Customer']]);
        $this->db->table('users')->insert([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password_hash' => password_hash('correct horse', PASSWORD_DEFAULT),
            'role_id' => 1, 'status' => 'active', 'token_version' => 0,
        ]);
        $this->db->table('users')->insert([
            'name' => 'Viewer', 'email' => 'viewer@example.com', 'password_hash' => 'x', 'role_id' => 2, 'status' => 'active', 'token_version' => 0,
        ]);
        $this->useOidc();
    }

    /** Swap in the OIDC service with $over on top of the test client (issuer ISS). */
    private function useOidc(array $over = []): void
    {
        Services::injectMock('wscOidc', new WscOidcService($over + [
            'issuer' => self::ISS, 'client_id' => 'aistudio', 'client_secret' => 's3cret', 'redirect_uri' => 'https://aistudio.example.test/api/auth/wsc/callback',
        ], fn (string $m, string $url, array $form = []) => $this->idp($m, $url, $form)));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('wscOidc');
        parent::tearDown();
    }

    /** URLs the fake IdP was asked for, in order. */
    private array $calls = [];

    private function idp(string $method, string $url, array $form): array
    {
        $this->calls[] = $url;
        foreach ([self::ISS, self::AUTH] as $iss) {
            if ($url === $iss . '/.well-known/openid-configuration') {
                return [200, json_encode(['issuer' => $iss, 'authorization_endpoint' => $iss . '/oauth/authorize',
                    'token_endpoint' => $iss . '/oauth/token', 'jwks_uri' => $iss . '/oauth/jwks'])];
            }
            if ($url === $iss . '/oauth/jwks') {
                $d = openssl_pkey_get_details(self::$key)['rsa'];
                return [200, json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'n' => WscOidcService::b64u($d['n']), 'e' => WscOidcService::b64u($d['e'])]]])];
            }
            if ($url === $iss . '/oauth/token' && $method === 'POST') {
                if ($form['code'] !== 'good-code' || $form['client_secret'] !== 's3cret' || ($form['code_verifier'] ?? '') === '') return [400, '{"error":"invalid_grant"}'];
                return [200, json_encode(['id_token' => $this->sign($this->claims)])];
            }
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
    private function begin(string $iss = self::ISS): array
    {
        $res = $this->get('api/auth/wsc/start?from=/models');
        $loc = $res->response()->getHeaderLine('Location');
        $this->assertStringStartsWith($iss . '/oauth/authorize?', $loc);
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

    // --- SSO Phase B: issuer bound to the flow --------------------------------------------------

    public function testIdTokenIssuerMustBeTheFlowsIssuerUnderTheAccountIssuer(): void
    {
        [$state, $nonce] = $this->begin(self::ISS);
        $this->staff($nonce, ['iss' => self::AUTH]);
        $this->assertStringContainsString('wrong issuer', $this->location($this->returnFromIdp($state))['wsc_error']);

        [$state, $nonce] = $this->begin(self::ISS);
        $this->staff($nonce, ['iss' => self::ISS]);
        $this->assertSame('ok', $this->location($this->returnFromIdp($state))['wsc'] ?? null);
    }

    public function testIdTokenIssuerMustBeTheFlowsIssuerUnderTheAuthIssuer(): void
    {
        $this->useOidc(['issuer' => self::AUTH, 'accepted_issuers' => [self::ISS, self::AUTH]]);
        $this->calls = [];
        [$state, $nonce] = $this->begin(self::AUTH);
        $this->staff($nonce, ['iss' => self::ISS]);
        $this->assertStringContainsString('wrong issuer', $this->location($this->returnFromIdp($state))['wsc_error']);

        [$state, $nonce] = $this->begin(self::AUTH);
        $this->staff($nonce, ['iss' => self::AUTH]);
        $this->assertSame('ok', $this->location($this->returnFromIdp($state))['wsc'] ?? null);
        // Every endpoint came from the auth issuer's discovery; nothing was asked of the account issuer.
        $this->assertSame([], array_values(array_filter($this->calls, fn ($u) => str_starts_with($u, self::ISS))));
        $this->assertContains(self::AUTH . '/oauth/token', $this->calls);
    }

    public function testAFlipMidSignInFinishesUnderTheStartIssuerOrFailsCleanly(): void
    {
        // Started under the account issuer, then WSC_OIDC_ISSUER moves to auth (account still accepted).
        [$state, $nonce] = $this->begin(self::ISS);
        $this->useOidc(['issuer' => self::AUTH, 'accepted_issuers' => [self::ISS, self::AUTH]]);
        $this->calls = [];
        $this->staff($nonce, ['iss' => self::ISS]);
        $this->assertSame('ok', $this->location($this->returnFromIdp($state))['wsc'] ?? null);
        $this->assertContains(self::ISS . '/oauth/token', $this->calls);

        // The start issuer is no longer accepted at all: that one sign-in fails with a clear message.
        [$state, $nonce] = $this->begin(self::AUTH);
        $this->useOidc(['issuer' => self::ISS, 'accepted_issuers' => [self::ISS]]);
        $this->staff($nonce, ['iss' => self::AUTH]);
        $this->assertStringContainsString('changed while you were signing in', $this->location($this->returnFromIdp($state))['wsc_error']);
    }

    public function testAcceptedIssuersDefaultToBothWorkSuiteIssuers(): void
    {
        $mk = fn (string $iss, $acc = '') => new WscOidcService(['issuer' => $iss, 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://x.test/cb', 'accepted_issuers' => $acc]);
        $this->assertSame(WscOidcService::WORKSUITE_ISSUERS, $mk('https://account.worksuitecloud.com/oauth')->acceptedIssuers());
        $this->assertSame(WscOidcService::WORKSUITE_ISSUERS, $mk('https://auth.worksuitecloud.com/')->acceptedIssuers());
        $this->assertSame(['https://idp.other.test'], $mk('https://idp.other.test')->acceptedIssuers());
        $this->assertSame(['https://a.test', 'https://b.test'], $mk('https://a.test', ' https://a.test/, https://b.test ')->acceptedIssuers());
    }

    public function testProvisioningIsAcceptedUnderTheOtherWorkSuiteIssuerAndRefusedUnderAForeignOne(): void
    {
        // The app moved to the auth issuer; OneAuth still signs provisioning events with the account issuer.
        $this->useOidc(['issuer' => self::AUTH, 'accepted_issuers' => [self::ISS, self::AUTH]]);
        $this->provision($this->event(['iss' => self::ISS]))->assertStatus(204);
        $this->provision($this->event(['iss' => self::AUTH, 'name' => 'Ops Two']))->assertStatus(204);
        $this->assertSame('Ops Two', $this->user('ops@example.com')->name);
        $this->provision($this->event(['iss' => 'https://evil.test', 'name' => 'Evil']))->assertStatus(401);
        $this->assertSame('Ops Two', $this->user('ops@example.com')->name);
        // Only the configured issuer accepted: the other one is refused.
        $this->useOidc(['issuer' => self::AUTH, 'accepted_issuers' => [self::AUTH]]);
        $this->provision($this->event(['iss' => self::ISS]))->assertStatus(401);
    }

    // --- SSO Phase B: back-channel logout -------------------------------------------------------

    private function logoutToken(array $over = [], array $drop = []): string
    {
        $c = $over + ['iss' => self::ISS, 'aud' => 'aistudio', 'iat' => time(), 'exp' => time() + 120, 'jti' => bin2hex(random_bytes(8)),
            'events' => [WscOidcService::LOGOUT_EVENT => new \stdClass()], 'sub' => 'staff:3', 'sid' => 'sid-1'];
        foreach ($drop as $k) unset($c[$k]);
        return $this->sign($c);
    }

    private function backchannel(string $token)
    {
        return $this->post('api/auth/wsc/backchannel-logout', ['logout_token' => $token]);
    }

    /** Sign in through WorkSuite Cloud with $sid; returns the refresh token. */
    private function wscSession(string $sid): string
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['sid' => $sid]);
        $res = $this->returnFromIdp($state);
        $this->assertSame('ok', $this->location($res)['wsc'] ?? null);
        return $res->response()->getCookie(\App\Controllers\Api\AuthController::REFRESH_COOKIE)->getValue();
    }

    private function passwordSession(): string
    {
        $res = $this->withBodyFormat('json')->post('api/auth/login', ['email' => 'admin@example.com', 'password' => 'correct horse']);
        $res->assertStatus(200);
        return json_decode($res->getJSON(), true)['data']['refresh_token'];
    }

    private function refresh(string $token)
    {
        return $this->withBodyFormat('json')->post('api/auth/refresh', ['refresh_token' => $token]);
    }

    public function testBackchannelLogoutBySidEndsThatSessionOnly(): void
    {
        $wsc   = $this->wscSession('sid-1');
        $other = $this->wscSession('sid-2');
        $pwd   = $this->passwordSession();
        $this->assertSame('sid-1', (new JwtService())->decode($wsc)['wsc_sid']);
        $access = json_decode($this->refresh($wsc)->getJSON(), true)['data']['access_token'];
        $this->withHeaders(['Authorization' => 'Bearer ' . $access])->get('api/auth/profile')->assertStatus(200);

        $res = $this->backchannel($this->logoutToken());
        $res->assertStatus(200);
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));

        $this->refresh($wsc)->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer ' . $access])->get('api/auth/profile')->assertStatus(401);
        $this->refresh($other)->assertStatus(200);
        $this->refresh($pwd)->assertStatus(200);
    }

    public function testBackchannelLogoutBySubOnlyEndsEveryOneAuthSessionButNotPasswordOnes(): void
    {
        $a   = $this->wscSession('sid-a');
        $b   = $this->wscSession('sid-b');
        $pwd = $this->passwordSession();
        $this->backchannel($this->logoutToken([], ['sid']))->assertStatus(200);
        $this->refresh($a)->assertStatus(401);
        $this->refresh($b)->assertStatus(401);
        $this->refresh($pwd)->assertStatus(200);
        // A later sign-in is a new session and is not ended by the earlier logout.
        sleep(1);
        $this->refresh($this->wscSession('sid-c'))->assertStatus(200);
        // A sub nobody is linked to: 200, nothing touched.
        $this->backchannel($this->logoutToken(['sub' => 'staff:404'], ['sid']))->assertStatus(200);
        $this->refresh($pwd)->assertStatus(200);
    }

    public function testBackchannelLogoutUnderTheOtherWorkSuiteIssuer(): void
    {
        $this->useOidc(['accepted_issuers' => [self::ISS, self::AUTH]]);
        $wsc = $this->wscSession('sid-1');
        $this->backchannel($this->logoutToken(['iss' => self::AUTH]))->assertStatus(200);
        $this->refresh($wsc)->assertStatus(401);
    }

    public function testBackchannelLogoutRefusesBadTokens(): void
    {
        $wsc = $this->wscSession('sid-1');
        $bad = [
            'foreign issuer' => $this->logoutToken(['iss' => 'https://evil.test']),
            'wrong aud'      => $this->logoutToken(['aud' => 'staff-desk']),
            'missing events' => $this->logoutToken([], ['events']),
            'other event'    => $this->logoutToken(['events' => ['urn:other' => new \stdClass()]]),
            'nonce present'  => $this->logoutToken(['nonce' => 'n']),
            'old iat'        => $this->logoutToken(['iat' => time() - 600, 'exp' => time() + 60]),
            'future iat'     => $this->logoutToken(['iat' => time() + 600]),
            'no iat'         => $this->logoutToken([], ['iat']),
            'expired'        => $this->logoutToken(['exp' => time() - 10]),
            'no sub or sid'  => $this->logoutToken([], ['sub', 'sid']),
            'no jti'         => $this->logoutToken([], ['jti']),
            'malformed'      => 'not-a-jwt',
        ];
        foreach ($bad as $why => $token) {
            $res = $this->backchannel($token);
            $this->assertSame(400, $res->response()->getStatusCode(), $why);
            $this->assertSame(['error' => 'invalid_request'], json_decode($res->getJSON(), true), $why);
        }
        $this->signWith = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->backchannel($this->logoutToken())->assertStatus(400);
        $this->signWith = null;
        $this->refresh($wsc)->assertStatus(200); // none of them ended the session
    }

    public function testBackchannelLogoutRefusesAReplayedJti(): void
    {
        $token = $this->logoutToken(['jti' => 'logout-once']);
        $this->backchannel($token)->assertStatus(200);
        $this->backchannel($token)->assertStatus(400);
    }

    public function testBackchannelLogoutWorksUnderLocalPolicy(): void
    {
        $wsc = $this->wscSession('sid-1');
        $this->useOidc(['policy' => 'local']);
        $this->backchannel($this->logoutToken())->assertStatus(200);
        $this->refresh($wsc)->assertStatus(401);
    }

    public function testSignInIsAudited(): void
    {
        [$state, $nonce] = $this->begin();
        $this->staff($nonce, ['acr' => 'urn:worksuite:acr:mfa', 'amr' => ['pwd', 'otp', 'mfa']]);
        $this->returnFromIdp($state);
        $row = $this->db->table('audit_logs')->like('action', 'wsc sign-in')->get()->getRow();
        $this->assertSame('wsc sign-in (account.example.test, acr mfa, amr pwd otp mfa)', $row->action);
    }

    // --- SSO Phase B: WSC_SSO_POLICY ------------------------------------------------------------

    private function config(): array
    {
        return json_decode($this->get('api/auth/wsc/config')->getJSON(), true)['data'];
    }

    /** Per-test emails: the per-email sign-in limiter outlives cache()->clean() between tests. */
    private string $admin = 'admin@example.com';
    private string $viewer = 'viewer@example.com';

    private function freshEmails(array $admin = [], array $viewer = []): void
    {
        $tag = bin2hex(random_bytes(4));
        [$this->admin, $this->viewer] = ["admin.{$tag}@example.com", "viewer.{$tag}@example.com"];
        $this->db->table('users')->where('id', 1)->update(['email' => $this->admin] + $admin);
        $this->db->table('users')->where('id', 2)->update(['email' => $this->viewer, 'password_hash' => password_hash('viewer pass', PASSWORD_DEFAULT)] + $viewer);
    }

    private function login(?string $email = null, string $password = 'correct horse')
    {
        return $this->withBodyFormat('json')->post('api/auth/login', ['email' => $email ?? $this->admin, 'password' => $password]);
    }

    public function testPolicyDefaultIsOptionalAndUnknownMeansOptional(): void
    {
        $this->assertSame(['enabled' => true, 'policy' => 'oneauth_optional'], $this->config());
        $this->useOidc(['policy' => 'sometimes']);
        $this->assertSame('oneauth_optional', $this->config()['policy']);
        $this->useOidc(['client_id' => '']);
        $this->assertSame(['enabled' => false, 'policy' => 'local'], $this->config());
    }

    public function testPolicyLocalTurnsTheButtonAndRoutesOff(): void
    {
        $this->freshEmails();
        [$state, $nonce] = $this->begin(); // started while optional
        $this->useOidc(['policy' => 'local']);
        $this->assertSame(['enabled' => false, 'policy' => 'local'], $this->config());
        $q = $this->location($this->get('api/auth/wsc/start'));
        $this->assertStringContainsString('turned off', $q['wsc_error']);
        $this->staff($nonce);
        $this->assertStringContainsString('turned off', $this->location($this->returnFromIdp($state))['wsc_error']);
        $this->provision($this->event())->assertStatus(204); // provisioning keeps working
        $this->login()->assertStatus(200);
    }

    public function testPolicyOptionalAndPreferredKeepPasswordSignIn(): void
    {
        $this->freshEmails(['wsc_sub' => 'staff:3', 'managed_by_wsc' => 1]);
        $this->login()->assertStatus(200);
        $this->useOidc(['policy' => 'oneauth_preferred']);
        $this->assertSame(['enabled' => true, 'policy' => 'oneauth_preferred'], $this->config());
        $this->login()->assertStatus(200);
    }

    public function testPolicyRequiredRefusesPasswordSignInOnTheServer(): void
    {
        $this->freshEmails(['wsc_sub' => 'staff:3', 'managed_by_wsc' => 1]);
        $this->useOidc(['policy' => 'oneauth_required']);
        $res = $this->login();
        $res->assertStatus(403);
        $this->assertSame('Sign in with WorkSuite Cloud. Password sign-in is turned off for this account.', json_decode($res->getJSON(), true)['message']);
        $this->login(null, 'wrong')->assertStatus(401); // a wrong password still says so
        // WorkSuite Cloud sign-in still works.
        [$state, $nonce] = $this->begin();
        $this->staff($nonce);
        $this->assertSame('ok', $this->location($this->returnFromIdp($state))['wsc'] ?? null);
    }

    public function testPolicyRequiredRefusesTheTwoFactorStepToo(): void
    {
        $this->freshEmails(['wsc_sub' => 'staff:3', 'totp_enabled_at' => date('Y-m-d H:i:s')]);
        $this->useOidc(['policy' => 'oneauth_required']);
        $this->login()->assertStatus(403); // no mfa_token is handed out
        $setup = $this->withBodyFormat('json')->post('api/auth/mfa/setup/confirm', ['mfa_token' => (new JwtService())->generateStepToken('mfa_setup', ['sub' => 1]),
            'setup_token' => (new JwtService())->generateStepToken('totp_setup', ['sub' => 1, 'secret' => 'JBSWY3DPEHPK3PXP']), 'code' => '000000']);
        $setup->assertStatus(403);
    }

    public function testPolicyRequiredKeepsBreakGlassAndNeverLinkedAccounts(): void
    {
        $this->freshEmails(['wsc_sub' => 'staff:3']);
        $this->useOidc(['policy' => 'oneauth_required', 'breakglass' => 'someone@example.com, ' . strtoupper($this->admin)]);
        $this->login()->assertStatus(200);                                   // break-glass (any letter case)
        $this->login($this->viewer, 'viewer pass')->assertStatus(200); // never linked (no wsc_sub)
        $this->useOidc(['policy' => 'oneauth_required']);
        $this->login()->assertStatus(403);
        $this->login($this->viewer, 'viewer pass')->assertStatus(200);
    }

    public function testRequiredPolicyNeedsOneAuthConfigured(): void
    {
        $this->freshEmails(['wsc_sub' => 'staff:3']);
        $this->useOidc(['policy' => 'oneauth_required', 'client_secret' => '']);
        $this->login()->assertStatus(200); // not configured: behaves as local
    }
}
