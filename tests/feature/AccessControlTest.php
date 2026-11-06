<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Privilege boundaries between administrators: a "manager" role holding users.manage,
 * roles.manage and storage.view must not be able to reach super-admin power.
 */
final class AccessControlTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const SUPER = 1, MANAGER = 2, VIEWER = 3;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        unset($_SERVER['ci_jwt_payload']);
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['roles', 'users', 'role_permissions', 'permissions', 'audit_logs', 'storage_objects', 'storage_placements', 'storage_nodes'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $f->addField($id + ['name' => ['type' => 'VARCHAR', 'constraint' => 64], 'label' => ['type' => 'VARCHAR', 'constraint' => 100],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'is_system' => ['type' => 'INTEGER', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true]])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['name' => ['type' => 'VARCHAR', 'constraint' => 120], 'label' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'group' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true]])->addKey('id', true)->createTable('permissions');
        $f->addField($id + ['role_id' => ['type' => 'INTEGER'], 'permission_id' => ['type' => 'INTEGER']])->addKey('id', true)->createTable('role_permissions');
        $f->addField($id + [
            'name' => ['type' => 'VARCHAR', 'constraint' => 120], 'email' => ['type' => 'VARCHAR', 'constraint' => 191],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255], 'role_id' => ['type' => 'INTEGER', 'null' => true],
            'avatar' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'last_login_at' => ['type' => 'DATETIME', 'null' => true], 'totp_secret' => ['type' => 'TEXT', 'null' => true],
            'totp_enabled_at' => ['type' => 'DATETIME', 'null' => true], 'totp_last_step' => ['type' => 'INTEGER', 'null' => true],
            'two_factor_required' => ['type' => 'INTEGER', 'default' => 0], 'token_version' => ['type' => 'INTEGER', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addKey('id', true)->createTable('users');
        $f->addField($id + ['user_id' => ['type' => 'INTEGER', 'null' => true], 'user_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 120], 'method' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'path' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true], 'status' => ['type' => 'INTEGER', 'null' => true],
            'ip' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true], 'meta' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true]])->addKey('id', true)->createTable('audit_logs');
        $f->addField($id + ['uuid' => ['type' => 'VARCHAR', 'constraint' => 36], 'app' => ['type' => 'VARCHAR', 'constraint' => 60],
            'object_key' => ['type' => 'VARCHAR', 'constraint' => 500], 'name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'size' => ['type' => 'INTEGER', 'default' => 0], 'sha256' => ['type' => 'VARCHAR', 'constraint' => 64], 'mime' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'policy_id' => ['type' => 'INTEGER', 'null' => true], 'owner_id' => ['type' => 'INTEGER', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addKey('id', true)->createTable('storage_objects');
        $f->addField($id + ['object_id' => ['type' => 'INTEGER'], 'node_id' => ['type' => 'INTEGER'], 'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'verified_at' => ['type' => 'DATETIME', 'null' => true], 'error' => ['type' => 'TEXT', 'null' => true]])->addKey('id', true)->createTable('storage_placements');
        $f->addField($id + ['name' => ['type' => 'VARCHAR', 'constraint' => 120], 'health' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true]])->addKey('id', true)->createTable('storage_nodes');

        $this->db->table('roles')->insertBatch([
            ['name' => 'super_admin', 'label' => 'Super admin', 'is_system' => 1],
            ['name' => 'manager', 'label' => 'Manager', 'is_system' => 0],
            ['name' => 'viewer', 'label' => 'Viewer', 'is_system' => 0],
        ]);
        $perms = ['users.view', 'users.manage', 'roles.view', 'roles.manage', 'storage.view', 'settings.manage'];
        foreach ($perms as $p) $this->db->table('permissions')->insert(['name' => $p]);
        foreach ([1, 2, 3, 4, 5] as $pid) $this->db->table('role_permissions')->insert(['role_id' => self::MANAGER, 'permission_id' => $pid]);
        $this->db->table('role_permissions')->insert(['role_id' => self::VIEWER, 'permission_id' => 1]);
        $hash = password_hash('correct horse', PASSWORD_DEFAULT);
        $this->db->table('users')->insertBatch([
            ['name' => 'Root', 'email' => 'root@example.com', 'password_hash' => $hash, 'role_id' => self::SUPER, 'status' => 'active', 'token_version' => 0],
            ['name' => 'Mia', 'email' => 'mia@example.com', 'password_hash' => $hash, 'role_id' => self::MANAGER, 'status' => 'active', 'token_version' => 0],
        ]);
        $this->db->table('storage_objects')->insert(['uuid' => 'u1', 'app' => 'backups', 'object_key' => 'db/1.sql.gz', 'name' => '1.sql.gz', 'sha256' => str_repeat('0', 64)]);
    }

    /** Bearer header for a user, minted directly so the per-account sign-in limit isn't hit. */
    private function as(string $email): array
    {
        $u = $this->db->table('users')->where('email', $email)->get()->getRowArray();
        $token = (new \App\Services\JwtService())->generateAccessToken(['id' => $u['id'], 'email' => $email, 'name' => $u['name'], 'role' => 'ignored', 'token_version' => $u['token_version']]);
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function putAs(string $email, string $path, array $body)
    {
        return $this->withHeaders($this->as($email))->withBodyFormat('json')->put($path, $body);
    }

    public function testManagerCannotPromoteThemselvesToSuperAdmin(): void
    {
        $this->putAs('mia@example.com', 'api/users/2', ['role_id' => self::SUPER])->assertStatus(403);
        $this->assertSame(self::MANAGER, (int) $this->db->table('users')->where('id', 2)->get()->getRow()->role_id);
    }

    public function testManagerCannotCreateASuperAdmin(): void
    {
        $this->withHeaders($this->as('mia@example.com'))->withBodyFormat('json')
            ->post('api/users', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'longpassword', 'role_id' => self::SUPER])
            ->assertStatus(403);
    }

    public function testManagerCannotEditASuperAdmin(): void
    {
        $this->putAs('mia@example.com', 'api/users/1', ['password' => 'taken-over-123'])->assertStatus(403);
        $this->assertTrue(password_verify('correct horse', $this->db->table('users')->where('id', 1)->get()->getRow()->password_hash));
    }

    public function testManagerCanStillManageLesserUsers(): void
    {
        $this->withHeaders($this->as('mia@example.com'))->withBodyFormat('json')
            ->post('api/users', ['name' => 'Val', 'email' => 'val@example.com', 'password' => 'longpassword', 'role_id' => self::VIEWER])
            ->assertStatus(201);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->putAs('root@example.com', 'api/users/2', ['status' => 'god-mode'])->assertStatus(422);
    }

    public function testLastSuperAdminCannotBeDemotedOrSuspended(): void
    {
        $this->putAs('root@example.com', 'api/users/1', ['status' => 'suspended'])->assertStatus(422);
        $this->putAs('root@example.com', 'api/users/1', ['role_id' => self::VIEWER])->assertStatus(422);
    }

    public function testManagerCannotWidenTheirOwnRole(): void
    {
        $this->putAs('mia@example.com', 'api/roles/' . self::MANAGER, ['permission_ids' => [1, 2, 3, 4, 5, 6]])->assertStatus(403);
    }

    public function testManagerCannotGrantPermissionsTheyLack(): void
    {
        $this->putAs('mia@example.com', 'api/roles/' . self::VIEWER, ['permission_ids' => [1, 6]])->assertStatus(403); // 6 = settings.manage
        $this->putAs('mia@example.com', 'api/roles/' . self::VIEWER, ['permission_ids' => [1, 5]])->assertStatus(200);
    }

    public function testBackupsAreSuperAdminOnly(): void
    {
        $this->withHeaders($this->as('mia@example.com'))->get('api/storage/files/1/download')->assertStatus(403);
    }

    public function testRequiringTwoFactorEndsSessionsAndBlocksRefresh(): void
    {
        $d = json_decode($this->withBodyFormat('json')->post('api/auth/login', ['email' => 'mia@example.com', 'password' => 'correct horse'])->getJSON(), true)['data'];
        $this->putAs('root@example.com', 'api/users/2', ['two_factor_required' => true])->assertStatus(200);
        $this->withHeaders(['Authorization' => 'Bearer ' . $d['access_token']])->get('api/auth/profile')->assertStatus(401);
        $this->withBodyFormat('json')->post('api/auth/refresh', ['refresh_token' => $d['refresh_token']])->assertStatus(401);
    }
}
