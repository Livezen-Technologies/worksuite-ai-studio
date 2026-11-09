<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** Audit S1/S7/B6: what a non-admin may change on their own API key. */
final class ApiKeyPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        unset($_SERVER['ci_jwt_payload']);
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['roles', 'users', 'role_permissions', 'permissions', 'audit_logs', 'api_keys', 'api_logs'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $n = static fn (string $t = 'VARCHAR') => ['type' => $t, 'null' => true];
        $f->addField($id + ['name' => $n(), 'label' => $n(), 'description' => $n(), 'is_system' => ['type' => 'INTEGER', 'default' => 0], 'created_at' => $n('DATETIME'), 'updated_at' => $n('DATETIME')])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['name' => $n(), 'label' => $n(), 'group' => $n()])->addKey('id', true)->createTable('permissions');
        $f->addField($id + ['role_id' => ['type' => 'INTEGER'], 'permission_id' => ['type' => 'INTEGER']])->addKey('id', true)->createTable('role_permissions');
        $f->addField($id + ['name' => $n(), 'email' => $n(), 'password_hash' => $n(), 'role_id' => $n('INTEGER'), 'avatar' => $n(), 'status' => ['type' => 'VARCHAR', 'default' => 'active'],
            'last_login_at' => $n('DATETIME'), 'totp_secret' => $n('TEXT'), 'totp_enabled_at' => $n('DATETIME'), 'totp_last_step' => $n('INTEGER'),
            'two_factor_required' => ['type' => 'INTEGER', 'default' => 0], 'token_version' => ['type' => 'INTEGER', 'default' => 0],
            'created_at' => $n('DATETIME'), 'updated_at' => $n('DATETIME'), 'deleted_at' => $n('DATETIME')])->addKey('id', true)->createTable('users');
        $f->addField($id + ['user_id' => $n('INTEGER'), 'user_name' => $n(), 'action' => $n(), 'method' => $n(), 'path' => $n(), 'status' => $n('INTEGER'), 'ip' => $n(), 'meta' => $n('TEXT'), 'created_at' => $n('DATETIME')])->addKey('id', true)->createTable('audit_logs');
        $f->addField($id + ['name' => $n(), 'user_id' => $n('INTEGER'), 'api_key' => $n(), 'secret_hash' => $n(), 'secret_prefix' => $n(), 'scopes' => $n(), 'allowed_models' => $n('TEXT'),
            'allowed_ips' => $n('TEXT'), 'daily_token_limit' => $n('INTEGER'), 'monthly_token_limit' => $n('INTEGER'),
            'rate_limit_per_min' => ['type' => 'INTEGER', 'default' => 60, 'null' => false], 'status' => ['type' => 'VARCHAR', 'default' => 'active'],
            'expires_at' => $n('DATETIME'), 'last_used_at' => $n('DATETIME'), 'created_at' => $n('DATETIME'), 'updated_at' => $n('DATETIME'),
            'key_type' => ['type' => 'VARCHAR', 'default' => 'personal'], 'application' => $n(), 'organization' => $n(), 'description' => $n(), 'rotation_days' => $n('INTEGER'),
            'rotated_at' => $n('DATETIME'), 'revoked_at' => $n('DATETIME'), 'token_version' => ['type' => 'INTEGER', 'default' => 0], 'admin_locked' => ['type' => 'INTEGER', 'default' => 0]])
            ->addKey('id', true)->createTable('api_keys');
        $f->addField($id + ['api_key_id' => $n('INTEGER'), 'total_tokens' => $n('INTEGER'), 'created_at' => $n('DATETIME')])->addKey('id', true)->createTable('api_logs');

        $this->db->table('roles')->insertBatch([['name' => 'admin', 'label' => 'Admin'], ['name' => 'customer', 'label' => 'Customer']]);
        foreach (['apikeys.view', 'apikeys.manage', 'apikeys.tokens'] as $i => $p) {
            $this->db->table('permissions')->insert(['name' => $p]);
            $this->db->table('role_permissions')->insert(['role_id' => 2, 'permission_id' => $i + 1]);
        }
        $this->db->table('users')->insertBatch([
            ['name' => 'Ada', 'email' => 'ada@x', 'role_id' => 1, 'status' => 'active'],
            ['name' => 'Cy', 'email' => 'cy@x', 'role_id' => 2, 'status' => 'active'],
        ]);
        $this->db->table('api_keys')->insert(['name' => 'cy key', 'user_id' => 2, 'api_key' => 'ohub_c', 'secret_hash' => 'x', 'scopes' => 'chat', 'status' => 'active']);
    }

    private function as(int $uid): array
    {
        $u = $this->db->table('users')->where('id', $uid)->get()->getRowArray();
        return ['Authorization' => 'Bearer ' . (new \App\Services\JwtService())->generateAccessToken(['id' => $uid, 'email' => $u['email'], 'name' => $u['name'], 'role' => 'x', 'token_version' => 0])];
    }

    private function edit(int $uid, array $body)
    {
        return $this->withHeaders($this->as($uid))->withBodyFormat('json')->put('api/apikeys/1', $body);
    }

    private function key(): array
    {
        return $this->db->table('api_keys')->where('id', 1)->get()->getRowArray();
    }

    public function testOwnerCannotGrantAdminScopesOrHugeLimits(): void
    {
        $this->edit(2, ['scopes' => ['chat', 'download_datasets']])->assertStatus(403);
        $this->edit(2, ['rate_limit_per_min' => 100000])->assertStatus(403);
        $this->edit(2, ['scopes' => 'download_datasets,chat'])->assertStatus(422);
        $this->assertSame('chat', $this->key()['scopes']);
        $this->edit(2, ['scopes' => ['chat', 'embeddings', 'transcribe'], 'rate_limit_per_min' => 300])->assertStatus(200);
        $this->assertSame(['chat,embeddings,transcribe', 300], [$this->key()['scopes'], (int) $this->key()['rate_limit_per_min']]);
    }

    public function testAdminEditLocksTheKeyForItsOwner(): void
    {
        $this->edit(1, ['status' => 'disabled', 'daily_token_limit' => 1000, 'scopes' => ['chat', 'download_models']])->assertStatus(200);
        $this->assertSame(1, (int) $this->key()['admin_locked']);
        $this->edit(2, ['status' => 'active'])->assertStatus(403);
        $this->edit(2, ['daily_token_limit' => ''])->assertStatus(403);
        $this->edit(2, ['admin_locked' => 0])->assertStatus(403);
        $this->edit(2, ['name' => 'renamed', 'description' => 'mine'])->assertStatus(200);
        $k = $this->key();
        $this->assertSame(['renamed', 'disabled', 1000], [$k['name'], $k['status'], (int) $k['daily_token_limit']]);
        $this->edit(1, ['admin_locked' => 0, 'status' => 'active'])->assertStatus(200);
        $this->edit(2, ['scopes' => ['chat']])->assertStatus(200);
    }

    public function testDisablingEndsAccessTokensAndEmptyRateMeansDefault(): void
    {
        $this->edit(2, ['status' => 'disabled'])->assertStatus(200);
        $this->assertSame(1, (int) $this->key()['token_version'], 're-enabling must not revive tokens issued before');
        $this->edit(2, ['status' => 'active'])->assertStatus(200);
        $this->assertSame(1, (int) $this->key()['token_version']);
        $this->edit(2, ['rate_limit_per_min' => ''])->assertStatus(200);
        $this->assertSame(60, (int) $this->key()['rate_limit_per_min']);
        $this->edit(2, ['rate_limit_per_min' => 0])->assertStatus(422);
    }
}
