<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** API keys die with their owner; gateway downloads expose only shared datasets and released packages. */
final class GatewaySecurityTest extends CIUnitTestCase
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
        foreach (['users', 'api_keys', 'datasets', 'engine_packages', 'roles', 'permissions', 'role_permissions'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true];
        $f->addField($id + ['name' => $null(), 'status' => $null(), 'role_id' => $null('INTEGER'), 'deleted_at' => $null('DATETIME')])->addKey('id', true)->createTable('users');
        $f->addField($id + ['name' => $null()])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['name' => $null()])->addKey('id', true)->createTable('permissions');
        $f->addField($id + ['role_id' => $null('INTEGER'), 'permission_id' => $null('INTEGER')])->addKey('id', true)->createTable('role_permissions');
        $this->db->table('roles')->insertBatch([['name' => 'viewer'], ['name' => 'data_team']]);
        $this->db->table('permissions')->insert(['name' => 'models.manage']);
        $this->db->table('role_permissions')->insert(['role_id' => 2, 'permission_id' => 1]);
        $f->addField($id + ['name' => $null(), 'user_id' => $null('INTEGER'), 'api_key' => $null(), 'secret_hash' => $null(), 'scopes' => $null(),
            'allowed_ips' => $null('TEXT'), 'rate_limit_per_min' => ['type' => 'INTEGER', 'default' => 60], 'status' => $null(),
            'expires_at' => $null('DATETIME'), 'last_used_at' => $null('DATETIME'), 'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])
            ->addKey('id', true)->createTable('api_keys');
        $f->addField($id + ['name' => $null(), 'rights_status' => $null(), 'item_count' => ['type' => 'INTEGER', 'default' => 0], 'visibility' => $null(),
            'created_by' => $null('INTEGER'), 'manifest_hash' => $null(), 'category' => $null(), 'language' => $null(), 'token_estimate' => $null('INTEGER'),
            'permitted_use' => $null(), 'rights_ref' => $null(), 'source_ref' => $null()])->addKey('id', true)->createTable('datasets');
        $f->addField($id + ['package_id' => $null(), 'state' => $null(), 'artifacts' => $null('TEXT')])->addKey('id', true)->createTable('engine_packages');

        $this->db->table('users')->insertBatch([
            ['name' => 'Ann', 'status' => 'active', 'role_id' => 1],
            ['name' => 'Bob', 'status' => 'active', 'role_id' => 1],   // portal user
            ['name' => 'Cara', 'status' => 'active', 'role_id' => 2],  // manages datasets in the admin area
        ]);
        $hash = password_hash('sk_secret', PASSWORD_DEFAULT);
        $this->db->table('api_keys')->insert(['name' => 'k', 'user_id' => 1, 'api_key' => 'ohub_k1', 'secret_hash' => $hash,
            'scopes' => 'download_datasets,download_models', 'status' => 'active']);
        $ds = ['rights_status' => 'approved', 'item_count' => 3, 'language' => 'si'];
        $this->db->table('datasets')->insertBatch([
            $ds + ['name' => 'shared', 'visibility' => 'shared', 'created_by' => 2],
            $ds + ['name' => 'bobs-private', 'visibility' => 'private', 'created_by' => 2],
            $ds + ['name' => 'anns-private', 'visibility' => 'private', 'created_by' => 1],
            $ds + ['name' => 'admin-dataset', 'visibility' => 'private', 'created_by' => 3],
        ]);
        $this->db->table('engine_packages')->insert(['package_id' => 'pkg-cand', 'state' => 'candidate', 'artifacts' => json_encode([['path' => 'model.gguf', 'sha256' => 'x', 'size_bytes' => 1]])]);
    }

    private function key(): array
    {
        return ['X-Api-Key' => 'ohub_k1', 'X-Api-Secret' => 'sk_secret'];
    }

    public function testKeyStopsWorkingWhenOwnerSuspendedOrDeleted(): void
    {
        $this->withHeaders($this->key())->get('api/v1/catalog')->assertStatus(200);
        $this->db->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->withHeaders($this->key())->get('api/v1/catalog')->assertStatus(403);
        $this->db->table('users')->where('id', 1)->update(['status' => 'active', 'deleted_at' => date('Y-m-d H:i:s')]);
        $this->withHeaders($this->key())->get('api/v1/catalog')->assertStatus(403);
    }

    public function testCatalogListsOnlyVisibleDatasetsAndReleasedPackages(): void
    {
        $body = json_decode($this->withHeaders($this->key())->get('api/v1/catalog')->getJSON(), true);
        $data = $body['data'] ?? $body;
        $this->assertEqualsCanonicalizing(['shared', 'anns-private', 'admin-dataset'], array_column($data['datasets'], 'name'));
        $this->assertSame([], $data['packages']);
    }

    public function testPrivateDatasetOfAnotherUserAndUnreleasedPackageAre404(): void
    {
        $this->withHeaders($this->key())->get('api/v1/datasets/2/download')->assertStatus(404);
        $this->withHeaders($this->key())->get('api/v1/packages/pkg-cand/files?path=model.gguf')->assertStatus(404);
    }
}
