<?php

namespace Tests\Feature;

use App\Libraries\Gateway\GatewayAssertion;
use App\Libraries\Gateway\GatewayAssertionError;
use App\Libraries\Gateway\GatewayReceiver;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\ApiGateway;
use Config\Services;

/**
 * The WorkSuite API Gateway assertion (WIRING.md §7, data-plane contract §8): the 16 cross-language vectors, the flag
 * off and on, caller mapping, scopes, the trusted path, replays, the rewritten path and the metering headers.
 * Tokens are signed here with the vectors' published TEST-ONLY key; model calls go to the fake engine.
 */
final class ApiGatewayAssertionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const GATEWAY_IP = '203.0.113.10';
    private const ORG = 'org-livezen';

    private static array $vectors;
    private static $proc;
    private static int $port = 0;
    private int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$vectors = json_decode((string) file_get_contents(__DIR__ . '/../_support/gateway/vectors.json'), true);
        self::$port = random_int(20000, 40000);
        self::$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../_support/fake_openai_engine.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['FAKE_ENGINE_LOG' => '/dev/null'] + getenv());
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', self::$port); $i++) usleep(100000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) { proc_terminate(self::$proc); proc_close(self::$proc); }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        unset($_SERVER['ohub_api_key'], $_SERVER['REQUEST_URI']);
        GatewayReceiver::reset();
        $_SERVER['REMOTE_ADDR'] = self::GATEWAY_IP;
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['users', 'roles', 'api_keys', 'api_logs', 'servers', 'models', 'model_registry', 'model_assignments', 'model_defaults', 'settings', 'queues', 'notifications', 'audit_logs', 'gateway_seen_jti'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true] + ($type === 'VARCHAR' ? ['constraint' => 191] : []);
        $f->addField($id + ['name' => $null(), 'status' => $null(), 'role_id' => $null('INTEGER'), 'deleted_at' => $null('DATETIME'), 'wsc_sub' => $null()])->addKey('id', true)->createTable('users');
        $f->addField($id + ['name' => $null()])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['name' => $null(), 'user_id' => $null('INTEGER'), 'api_key' => $null(), 'secret_hash' => $null(), 'scopes' => $null(), 'allowed_models' => $null('TEXT'),
            'allowed_ips' => $null('TEXT'), 'rate_limit_per_min' => ['type' => 'INTEGER', 'default' => 60], 'status' => $null(), 'daily_token_limit' => $null('INTEGER'),
            'monthly_token_limit' => $null('INTEGER'), 'expires_at' => $null('DATETIME'), 'last_used_at' => $null('DATETIME'), 'created_at' => $null('DATETIME'),
            'updated_at' => $null('DATETIME'), 'revoked_at' => $null('DATETIME'), 'token_version' => ['type' => 'INTEGER', 'default' => 0], 'managed_by_wsc' => ['type' => 'INTEGER', 'default' => 0], 'application' => $null(),
            'previous_secret_hash' => $null(), 'previous_secret_expires_at' => $null('DATETIME'), 'max_output_tokens' => $null('INTEGER'), 'gateway_credential_id' => $null()])
            ->addKey('id', true)->createTable('api_keys');
        $f->addField($id + ['api_key_id' => $null('INTEGER'), 'user_id' => $null('INTEGER'), 'endpoint' => $null(), 'model' => $null(), 'server_id' => $null('INTEGER'),
            'method' => $null(), 'prompt_tokens' => $null('INTEGER'), 'completion_tokens' => $null('INTEGER'), 'total_tokens' => $null('INTEGER'),
            'processing_ms' => $null('INTEGER'), 'status' => $null('INTEGER'), 'client_ip' => $null(), 'country' => $null(), 'error' => $null(), 'created_at' => $null('DATETIME'), 'end_user' => $null()])
            ->addKey('id', true)->createTable('api_logs');
        $f->addField($id + ['name' => $null(), 'kind' => $null(), 'api_url' => $null(), 'auth_token' => $null(), 'status' => $null(), 'health_score' => ['type' => 'INTEGER', 'default' => 100],
            'is_enabled' => ['type' => 'INTEGER', 'default' => 1], 'deleted_at' => $null('DATETIME'), 'gpu_vram_gb' => $null('REAL'), 'vram_used_gb' => $null('REAL'),
            'asr_enabled' => ['type' => 'INTEGER', 'default' => 1], 'asr_port' => $null('INTEGER'), 'asr_device' => $null(), 'asr_seen_at' => $null('DATETIME'),
            'gpu_model' => $null(), 'gpu_count' => $null('INTEGER')])->addKey('id', true)->createTable('servers');
        $f->addField($id + ['server_id' => $null('INTEGER'), 'name' => $null(), 'parameter_size' => $null(), 'quantization' => $null(), 'size_bytes' => $null('BIGINT'), 'status' => $null()])->addKey('id', true)->createTable('models');
        $f->addField($id + ['name' => $null(), 'display_name' => $null(), 'type' => $null(), 'version' => $null(), 'family' => $null(), 'parameter_size' => $null(),
            'quantization' => $null(), 'vram_gb' => $null('REAL'), 'context_length' => $null('INTEGER'), 'capabilities' => $null('TEXT'), 'engine' => $null(),
            'source' => $null(), 'description' => $null(), 'access_roles' => $null('TEXT'), 'keep_loaded' => ['type' => 'INTEGER', 'default' => 0],
            'idle_unload_minutes' => $null('INTEGER'), 'enabled' => ['type' => 'INTEGER', 'default' => 1], 'created_by' => $null('INTEGER'),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_registry');
        $f->addField($id + ['registry_id' => $null('INTEGER'), 'server_id' => $null('INTEGER'), 'state' => $null(), 'job_id' => $null('INTEGER'), 'error' => $null(),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_assignments');
        $f->addField($id + ['task' => $null(), 'application' => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => ''], 'model_name' => $null(), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_defaults');
        $f->addField($id + ['group' => $null(), 'key' => $null(), 'value' => $null('TEXT'), 'is_secret' => ['type' => 'INTEGER', 'default' => 0], 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('settings');
        $f->addField($id + ['type' => $null(), 'status' => $null(), 'error' => $null()])->addKey('id', true)->createTable('queues');
        $f->addField($id + ['user_id' => $null('INTEGER'), 'type' => $null(), 'title' => $null(), 'body' => $null('TEXT'), 'level' => $null(), 'read_at' => $null('DATETIME'), 'created_at' => $null('DATETIME')])->addKey('id', true)->createTable('notifications');
        $f->addField($id + ['user_id' => $null('INTEGER'), 'user_name' => $null(), 'action' => $null(), 'method' => $null(), 'path' => $null(), 'status' => $null('INTEGER'),
            'ip' => $null(), 'meta' => $null('TEXT'), 'created_at' => $null('DATETIME')])->addKey('id', true)->createTable('audit_logs');
        $f->addField(['jti' => ['type' => 'CHAR', 'constraint' => 64], 'expires_at' => ['type' => 'DATETIME']])->addKey('jti', true)->createTable('gateway_seen_jti');

        $this->db->table('roles')->insertBatch([['name' => 'customer'], ['name' => 'developer']]);
        $this->db->table('users')->insertBatch([
            ['name' => 'Ann', 'status' => 'active', 'role_id' => 1, 'wsc_sub' => 'oneauth|ann'],
            ['name' => 'Svc', 'status' => 'active', 'role_id' => 1, 'wsc_sub' => null],
            ['name' => 'Bob', 'status' => 'active', 'role_id' => 1, 'wsc_sub' => 'oneauth|bob'], // linked, but no key bound
        ]);
        $key = ['secret_hash' => password_hash('sk_s', PASSWORD_DEFAULT), 'status' => 'active', 'rate_limit_per_min' => 10000];
        foreach ([
            $key + ['name' => 'direct', 'user_id' => 1, 'api_key' => 'ohub_direct', 'scopes' => 'chat,generate,embeddings,models'],
            $key + ['name' => 'console app', 'user_id' => 2, 'api_key' => 'ohub_svc', 'scopes' => 'chat,generate,embeddings,models,classify',
                'application' => 'HR portal', 'gateway_credential_id' => 'cred-svc'],
            $key + ['name' => 'ann via oauth app', 'user_id' => 1, 'api_key' => 'ohub_ann', 'scopes' => 'chat,models', 'gateway_credential_id' => 'cred-oauth-app'],
            $key + ['name' => 'locked', 'user_id' => 2, 'api_key' => 'ohub_locked', 'scopes' => 'chat', 'gateway_credential_id' => 'cred-ip', 'allowed_ips' => json_encode(['10.0.0.1'])],
        ] as $row) $this->db->table('api_keys')->insert($row);
        $this->db->table('servers')->insert(['name' => 'fake', 'kind' => 'vllm', 'api_url' => 'http://127.0.0.1:' . self::$port, 'status' => 'online', 'health_score' => 90]);
        foreach (['fake-chat', 'fake-embed'] as $m) $this->db->table('models')->insert(['server_id' => 1, 'name' => $m, 'status' => 'available']);

        $this->gateway(true);
    }

    protected function tearDown(): void
    {
        GatewayReceiver::reset();
        Services::reset(true);
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------------------------------------------

    /** Installs a receiver with the vectors' test key, this test's clock and the given flag. */
    private function gateway(bool $enabled, string $cidrs = self::GATEWAY_IP . '/32'): void
    {
        $cfg = new ApiGateway();
        $cfg->enabled = $enabled;
        $cfg->trustedCidrs = $cidrs;
        $cfg->orgs = self::ORG;
        $keys = GatewayAssertion::staticKeys(GatewayAssertion::pemsFromJwks(self::$vectors['jwks']));
        Services::injectMock('gatewayReceiver', new GatewayReceiver($cfg, $keys));
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** An ES256 assertion signed with the vectors' TEST-ONLY key. */
    private function token(array $claims): string
    {
        $now = time();
        $c = $claims + ['iss' => GatewayAssertion::DEFAULT_ISSUER, 'aud' => 'ai-studio', 'iat' => $now, 'exp' => $now + 30,
            'jti' => 'req_test:' . (++$this->seq) . ':' . bin2hex(random_bytes(4)), 'sub' => 'cred:cred-svc', 'sub_type' => 'credential',
            'org' => self::ORG, 'project' => 'proj-ais', 'env' => 'staging', 'cred' => 'cred-svc', 'cred_type' => 'api_key',
            'scopes' => ['aistudio.chat', 'aistudio.models.read', 'aistudio.embeddings'], 'api' => 'aistudio.v1', 'route' => 'route-1',
            'region' => 'global', 'node' => 'gw-01'];
        $input = self::b64(json_encode(['alg' => 'ES256', 'typ' => 'wsapi-gateway+jwt', 'kid' => 'test-vector-1'])) . '.' . self::b64(json_encode($c));
        openssl_sign($input, $der, self::$vectors['test_only_key']['private_key_pem'], OPENSSL_ALGO_SHA256);
        // DER ECDSA-Sig-Value → raw r || s
        $o = 3;
        $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 1 + $rl + 1;
        $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
        $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);

        return $input . '.' . self::b64($raw);
    }

    private function gw(string $method, string $path, array $claims = [], array $body = [], ?string $token = null)
    {
        $token ??= $this->token($claims + ['htm' => strtoupper($method), 'htp' => '/' . $path]);
        $req = $this->withHeaders(['Authorization' => 'WS-API-Gateway ' . $token]);
        return $method === 'get' ? $req->get($path) : $req->withBodyFormat('json')->post($path, $body);
    }

    private function json($res): array
    {
        return json_decode((string) $res->response()->getBody(), true) ?? [];
    }

    // ------------------------------------------------------------------------------------------------------------
    // the verifier
    // ------------------------------------------------------------------------------------------------------------

    public function testCrossLanguageVectors(): void
    {
        $v = self::$vectors;
        $seen = [];
        $g = new GatewayAssertion(GatewayAssertion::staticKeys(GatewayAssertion::pemsFromJwks($v['jwks'])), $v['issuer'], $v['audience'],
            static function (string $id, int $until) use (&$seen): bool { if (isset($seen[$id])) return false; $seen[$id] = $until; return true; },
            static fn (): float => (float) $v['now'], $v['leeway_s'], $v['max_lifetime_s']);
        $this->assertCount(16, $v['vectors']);
        foreach ($v['vectors'] as $x) {
            try {
                $claims = $g->verify($x['token'], $x['method'], $x['path']);
                $this->assertSame('accept', $x['expect'], $x['name']);
                $this->assertEquals($x['claims'], $claims, $x['name']);
            } catch (GatewayAssertionError $e) {
                $this->assertSame('reject', $x['expect'], $x['name'] . ': ' . $e->getMessage());
                $this->assertSame($x['reason'], $e->reason, $x['name']);
            }
        }
    }

    public function testReplayRecordIsSharedThroughTheDatabase(): void
    {
        $r = Services::gatewayReceiver();
        $this->assertTrue($r->claimJti("ai-studio\0req:1", time() + 60));
        $this->assertFalse($r->claimJti("ai-studio\0req:1", time() + 60));
        $this->assertSame(1, $this->db->table('gateway_seen_jti')->countAllResults());
    }

    public function testRemoteKeysCacheAndRefetchPolicy(): void
    {
        $t = 1000.0;
        $state = null;
        $fetches = 0;
        $fail = false;
        $jwks = json_encode(self::$vectors['jwks']);
        $keys = GatewayAssertion::remoteKeys('https://gw.test/jwks', static function () use (&$state) { return $state; },
            static function (array $s) use (&$state): void { $state = $s; },
            static function () use (&$fetches, &$fail, $jwks): string { $fetches++; if ($fail) throw new \RuntimeException('down'); return $jwks; },
            static function () use (&$t): float { return $t; });
        $this->assertNotFalse($keys('test-vector-1'));
        $keys('test-vector-1');
        $this->assertSame(1, $fetches, 'cached');
        try { $keys('rotated'); } catch (GatewayAssertionError $e) { $this->assertSame('unknown_kid', $e->reason); }
        $this->assertSame(1, $fetches, 'an unknown kid refetches at most every 30 s');
        $t += 31;
        try { $keys('rotated'); } catch (GatewayAssertionError) {}
        $this->assertSame(2, $fetches, 'after 30 s an unknown kid refetches');
        $t += 301; $fail = true;
        $this->assertNotFalse($keys('test-vector-1'), 'gateway unreachable: the cached keys keep working');
        $this->assertSame(3, $fetches);
    }

    // ------------------------------------------------------------------------------------------------------------
    // the flag
    // ------------------------------------------------------------------------------------------------------------

    public function testFlagOffRefusesTheSchemeAndLeavesDirectAccessAlone(): void
    {
        $this->gateway(false);
        $res = $this->gw('get', 'api/v1/models');
        $res->assertStatus(401);
        $this->assertStringContainsString('GATEWAY_DISABLED', $this->json($res)['message']);
        $this->assertSame('WS-API-Gateway', $res->response()->getHeaderLine('WWW-Authenticate'));

        $direct = $this->withHeaders(['Authorization' => 'Bearer ohub_direct:sk_s'])->get('api/v1/models');
        $direct->assertStatus(200);
        $this->assertSame('', $direct->response()->getHeaderLine('X-WS-AI-Tokens-In'));
    }

    public function testDirectAccessStillWorksWithTheFlagOn(): void
    {
        $res = $this->withHeaders(['Authorization' => 'Bearer ohub_direct:sk_s'])->withBodyFormat('json')
            ->post('api/v1/embeddings', ['model' => 'fake-embed', 'input' => 'hello']);
        $res->assertStatus(200);
        $this->assertSame('', $res->response()->getHeaderLine('X-WS-AI-Tokens-In'), 'metering headers go to gateway callers only');
    }

    // ------------------------------------------------------------------------------------------------------------
    // flag on: mapping, scopes, path, peer, replay, metering, audit
    // ------------------------------------------------------------------------------------------------------------

    public function testCredentialCallerMapsToItsBoundKeyAndIsMetered(): void
    {
        $res = $this->gw('post', 'api/openai/v1/chat/completions', [], ['model' => 'fake-chat', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
        $this->assertSame(200, $res->response()->getStatusCode(), $res->response()->getHeaderLine('X-WS-Gateway-Reason') . ' ' . $res->response()->getBody());
        $h = $res->response();
        $this->assertMatchesRegularExpression('/^\d+$/', $h->getHeaderLine('X-WS-AI-Tokens-In'));
        $this->assertMatchesRegularExpression('/^\d+$/', $h->getHeaderLine('X-WS-AI-Tokens-Out'));
        $this->assertSame('fake-chat', $h->getHeaderLine('X-WS-AI-Model'));
        $this->assertSame('HR portal', $h->getHeaderLine('X-WS-AI-App'));
        $this->assertSame(2, (int) $this->db->table('api_logs')->where('status', 200)->get()->getRowArray()['api_key_id'], 'counted against the bound key');

        $audit = $this->db->table('audit_logs')->get()->getRowArray();
        $this->assertSame('gateway cred:cred-svc', $audit['user_name']);
        $meta = json_decode($audit['meta'], true)['gateway'];
        foreach (['jti', 'sub', 'sub_type', 'cred', 'client_id', 'project', 'env', 'node'] as $k) $this->assertArrayHasKey($k, $meta);
        $this->assertSame('gw-01', $meta['node']);
        $this->assertStringStartsWith('req_test:', $meta['jti']);
    }

    public function testReadsThroughTheGatewayAreAuditedToo(): void
    {
        $this->gw('get', 'api/v1/models')->assertStatus(200);
        $this->assertSame(1, $this->db->table('audit_logs')->where('method', 'GET')->countAllResults());
    }

    public function testScopesNarrowTheKeyAndDenyByDefault(): void
    {
        // aistudio.predict is not asserted: classify is refused although the key has the scope
        $this->gw('post', 'api/v1/classify', [], ['model' => 'x', 'text' => 'y'])->assertStatus(403);
        // only aistudio.translate: /chat is refused, translate passes the gate
        $res = $this->gw('post', 'api/v1/chat', ['scopes' => ['aistudio.translate']], ['model' => 'fake-chat', 'messages' => [['role' => 'user', 'content' => 'x']]]);
        $res->assertStatus(403);
        $this->assertSame('insufficient_scope', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));
        // unknown scopes grant nothing
        $this->gw('get', 'api/v1/models', ['scopes' => ['aistudio.everything']])->assertStatus(403);
        // file downloads are not offered through the gateway
        $res = $this->gw('get', 'api/v1/datasets/1/download', ['scopes' => ['aistudio.models.read']]);
        $res->assertStatus(403);
        $this->assertSame('route', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));

        $this->assertSame('chat,generate', GatewayReceiver::narrowScopes('chat,generate,embeddings', ['aistudio.chat']));
        $this->assertSame('', GatewayReceiver::narrowScopes('models', ['aistudio.chat']), 'never wider than the key');
    }

    public function testUserCallerMapsOnlyToALinkedUsersBoundKey(): void
    {
        $user = ['sub' => 'oneauth|ann', 'sub_type' => 'user', 'cred' => 'cred-oauth-app', 'cred_type' => 'oauth_app', 'client_id' => 'wsc_hr'];
        $this->gw('get', 'api/openai/v1/models', $user)->assertStatus(200);
        // embeddings: asserted, but Ann's own key lacks it → the app's own check refuses
        $this->gw('post', 'api/v1/embeddings', $user, ['model' => 'fake-embed', 'input' => 'x'])->assertStatus(403);

        foreach ([
            ['sub' => 'oneauth|bob'] + $user,     // linked, no key bound to that app
            ['sub' => 'oneauth|nobody'] + $user,  // not linked: never created or linked here
            ['cred' => 'cred-svc'] + $user,       // the service key is not Ann's
        ] as $claims) {
            $res = $this->gw('get', 'api/openai/v1/models', $claims);
            $res->assertStatus(401);
            $this->assertSame('unmapped', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));
        }
        $this->assertSame(0, $this->db->table('users')->where('wsc_sub', 'oneauth|nobody')->countAllResults());
    }

    public function testCredentialMappingRefusals(): void
    {
        $cases = [
            'unmapped' => ['sub' => 'cred:cred-unknown', 'cred' => 'cred-unknown'],
            'other org' => ['org' => 'org-someone-else'],
            'sub/cred mismatch' => ['cred' => 'cred-other'],
            'anonymous' => ['sub' => 'anonymous', 'sub_type' => 'anonymous', 'cred' => ''],
        ];
        foreach ($cases as $name => $claims) {
            $this->gw('get', 'api/v1/models', $claims)->assertStatus(401);
        }
        $res = $this->gw('get', 'api/v1/models', ['sub' => 'cred:cred-ip', 'cred' => 'cred-ip', 'scopes' => ['aistudio.chat', 'aistudio.models.read']]);
        $res->assertStatus(403);
        $this->assertSame('ip_allowlist', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));
    }

    public function testUntrustedPeerIsRefused(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $res = $this->gw('get', 'api/v1/models');
        $res->assertStatus(401);
        $this->assertSame('untrusted_peer', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));

        // nothing trusted (the default): refused too
        $_SERVER['REMOTE_ADDR'] = self::GATEWAY_IP;
        $this->gateway(true, '');
        $this->gw('get', 'api/v1/models')->assertStatus(401);
    }

    public function testBehindTheLocalProxyOnlyTheLastForwardedHopCounts(): void
    {
        $app = config('App');
        $app->proxyIPs = ['127.0.0.1' => 'X-Forwarded-For'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $tok = fn () => $this->token(['htm' => 'GET', 'htp' => '/api/v1/models']);
        $this->withHeaders(['Authorization' => 'WS-API-Gateway ' . $tok(), 'X-Forwarded-For' => '198.51.100.7, ' . self::GATEWAY_IP])
            ->get('api/v1/models')->assertStatus(200);
        $res = $this->withHeaders(['Authorization' => 'WS-API-Gateway ' . $tok(), 'X-Forwarded-For' => self::GATEWAY_IP . ', 198.51.100.7'])
            ->get('api/v1/models');
        $res->assertStatus(401);
        $this->assertSame('untrusted_peer', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));
        $app->proxyIPs = [];
    }

    public function testReplayedAssertionIsRefused(): void
    {
        $token = $this->token(['htm' => 'GET', 'htp' => '/api/v1/models']);
        $this->gw('get', 'api/v1/models', [], [], $token)->assertStatus(200);
        GatewayReceiver::reset();
        $res = $this->gw('get', 'api/v1/models', [], [], $token);
        $res->assertStatus(401);
        $this->assertSame('replay', $res->response()->getHeaderLine('X-WS-Gateway-Reason'));
    }

    public function testHtpIsTheRewrittenAppPath(): void
    {
        // The gateway serves /v1/aistudio/openai/v1/models and rewrites it to /api/openai/v1/models: htp is the latter.
        $this->gw('get', 'api/openai/v1/models', ['htp' => '/v1/aistudio/openai/v1/models'])->assertStatus(401);
        $this->gw('get', 'api/openai/v1/models', ['htm' => 'POST'])->assertStatus(401);
        $this->gw('get', 'api/openai/v1/models', ['aud' => 'worksuite-cloud'])->assertStatus(401);
        $this->gw('get', 'api/openai/v1/models')->assertStatus(200);
        $this->assertSame('/api/openai/v1/models', GatewayAssertion::requestPath('/api/openai/v1/models?limit=5'));
    }

    public function testDashboardRoutesRefuseTheScheme(): void
    {
        $this->gw('get', 'api/apikeys')->assertStatus(401);
        $this->gw('get', 'api/auth/profile')->assertStatus(401);
    }
}
