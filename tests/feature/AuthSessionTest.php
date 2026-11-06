<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Sign-in, the auth filter and session revocation, through the real routes and filters.
 * Builds only the tables these paths touch (the full migration set targets MySQL).
 */
final class AuthSessionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        unset($_SERVER['ci_jwt_payload']); // set per request by the auth filter; tests share one process
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
    }

    private function login(string $password = 'correct horse', string $email = 'admin@example.com')
    {
        return $this->withBodyFormat('json')->post('api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function tokens(): array
    {
        $d = json_decode($this->login()->getJSON(), true)['data'];
        return [$d['access_token'], $d['refresh_token']];
    }

    private function profile(string $access)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$access}"])->get('api/auth/profile');
    }

    public function testLoginIssuesWorkingTokens(): void
    {
        [$access] = $this->tokens();
        $this->profile($access)->assertStatus(200);
    }

    public function testLogoutRevokesAccessAndRefreshTokens(): void
    {
        [$access, $refresh] = $this->tokens();
        $this->withHeaders(['Authorization' => "Bearer {$access}"])->withBodyFormat('json')->post('api/auth/logout', [])->assertStatus(200);
        $this->profile($access)->assertStatus(401);
        $this->withBodyFormat('json')->post('api/auth/refresh', ['refresh_token' => $refresh])->assertStatus(401);
    }

    public function testSuspendedUserIsLockedOutImmediately(): void
    {
        [$access] = $this->tokens();
        $this->db->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->profile($access)->assertStatus(401);
    }

    public function testRoleComesFromTheDatabaseNotTheToken(): void
    {
        [$access] = $this->tokens();
        $this->db->table('users')->where('id', 1)->update(['role_id' => 2]); // demoted to viewer
        // users.view is not granted to viewer, so the admin-only list is now forbidden.
        $this->withHeaders(['Authorization' => "Bearer {$access}"])->get('api/users')->assertStatus(403);
    }

    public function testLoginIsRateLimitedPerAccount(): void
    {
        // A fresh account bucket so earlier tests' sign-ins don't count.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->login('wrong', 'stuffing@example.com')->response()->getStatusCode(), "attempt {$i}");
        }
        $this->login('wrong', 'stuffing@example.com')->assertStatus(429);
    }

    public function testFailedSignInIsAuditedWithTheEmailButNotThePassword(): void
    {
        $this->login('hunter2-secret', 'audit-probe@example.com')->assertStatus(401);
        $row = $this->db->table('audit_logs')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('sign-in attempt: audit-probe@example.com', $row['user_name']);
        $this->assertSame(401, (int) $row['status']);
        $this->assertStringNotContainsString('hunter2', json_encode($row));
    }
}
