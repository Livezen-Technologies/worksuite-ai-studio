<?php

namespace Tests\Feature;

use App\Services\ApiTokenService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** Short-lived JWT access tokens, OAuth client_credentials and revocation on the /api/v1 gateway. */
final class ApiTokenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        unset($_SERVER['ohub_api_key']);
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['users', 'api_keys', 'datasets', 'engine_packages'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true] + ($type === 'VARCHAR' ? ['constraint' => 191] : []);
        $f->addField($id + ['name' => $null(), 'status' => $null(), 'role_id' => $null('INTEGER'), 'deleted_at' => $null('DATETIME')])->addKey('id', true)->createTable('users');
        $f->addField($id + ['name' => $null(), 'user_id' => $null('INTEGER'), 'api_key' => $null(), 'secret_hash' => $null(), 'scopes' => $null(),
            'allowed_ips' => $null('TEXT'), 'rate_limit_per_min' => ['type' => 'INTEGER', 'default' => 60], 'status' => $null(),
            'expires_at' => $null('DATETIME'), 'last_used_at' => $null('DATETIME'), 'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME'),
            'revoked_at' => $null('DATETIME'), 'token_version' => ['type' => 'INTEGER', 'default' => 0], 'key_type' => $null()])
            ->addKey('id', true)->createTable('api_keys');
        $f->addField($id + ['name' => $null(), 'rights_status' => $null(), 'item_count' => ['type' => 'INTEGER', 'default' => 0], 'visibility' => $null(),
            'created_by' => $null('INTEGER'), 'manifest_hash' => $null(), 'category' => $null(), 'language' => $null(), 'token_estimate' => $null('INTEGER'),
            'permitted_use' => $null(), 'rights_ref' => $null(), 'source_ref' => $null()])->addKey('id', true)->createTable('datasets');
        $f->addField($id + ['package_id' => $null(), 'state' => $null(), 'artifacts' => $null('TEXT')])->addKey('id', true)->createTable('engine_packages');
        $this->db->table('users')->insert(['name' => 'Ann', 'status' => 'active', 'role_id' => 1]);
        $this->db->table('api_keys')->insert(['name' => 'k', 'user_id' => 1, 'api_key' => 'ohub_k1', 'secret_hash' => password_hash('sk_secret', PASSWORD_DEFAULT),
            'scopes' => 'download_datasets,models', 'status' => 'active', 'token_version' => 0]);
    }

    private function key(): array
    {
        return $this->db->table('api_keys')->where('id', 1)->get()->getRowArray();
    }

    private function bearer(string $t): array
    {
        return ['Authorization' => 'Bearer ' . $t];
    }

    public function testJwtAccessTokenAuthenticatesAndKeepsItsScopes(): void
    {
        $tok = (new ApiTokenService())->issue($this->key(), ['download_datasets'], 600);
        $this->assertSame('download_datasets', $tok['scope']);
        $this->withHeaders($this->bearer($tok['access_token']))->get('api/v1/catalog')->assertStatus(200);
        // 'models' was not granted to this token even though the key has it.
        $this->withHeaders($this->bearer($tok['access_token']))->get('api/v1/models')->assertStatus(403);
    }

    public function testTokenCannotAskForScopesTheKeyLacks(): void
    {
        $tok = (new ApiTokenService())->issue($this->key(), ['chat', 'models'], 600);
        $this->assertSame('models', $tok['scope']);
    }

    public function testRevokingOrRotatingTheKeyKillsTokens(): void
    {
        $tok = (new ApiTokenService())->issue($this->key(), null, 600)['access_token'];
        $this->db->table('api_keys')->where('id', 1)->update(['token_version' => 1]);
        $this->withHeaders($this->bearer($tok))->get('api/v1/catalog')->assertStatus(401);

        $tok = (new ApiTokenService())->issue($this->key(), null, 600)['access_token'];
        $this->db->table('api_keys')->where('id', 1)->update(['revoked_at' => date('Y-m-d H:i:s'), 'status' => 'revoked']);
        $this->withHeaders($this->bearer($tok))->get('api/v1/catalog')->assertStatus(403);
        $this->withHeaders(['X-Api-Key' => 'ohub_k1', 'X-Api-Secret' => 'sk_secret'])->get('api/v1/catalog')->assertStatus(403);
    }

    public function testForgedTokenIsRejected(): void
    {
        $tok = (new ApiTokenService())->issue($this->key(), null, 600)['access_token'];
        $this->withHeaders($this->bearer($tok . 'x'))->get('api/v1/catalog')->assertStatus(401);
    }

    public function testOAuthClientCredentials(): void
    {
        $res = $this->withBodyFormat('json')->post('api/v1/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'ohub_k1', 'client_secret' => 'sk_secret', 'scope' => 'download_datasets']);
        $res->assertStatus(200);
        $body = json_decode($res->getJSON(), true);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame('download_datasets', $body['scope']);
        $this->withHeaders($this->bearer($body['access_token']))->get('api/v1/catalog')->assertStatus(200);

        $this->withBodyFormat('json')->post('api/v1/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'ohub_k1', 'client_secret' => 'nope'])->assertStatus(401);
        $this->withBodyFormat('json')->post('api/v1/oauth/token', ['grant_type' => 'password'])->assertStatus(400);
    }
}
