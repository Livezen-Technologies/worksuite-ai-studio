<?php

namespace Tests\Feature;

use App\Libraries\PasswordPolicy;
use App\Libraries\Storage\LocalDriver;
use App\Services\TotpService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Self-service password change, the password policy, TOTP replay protection and small config guards.
 */
final class AccountPasswordTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        \Config\Services::resetSingle('throttler');
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

    protected function tearDown(): void
    {
        cache()->clean(); // do not leave throttle buckets for the next test class
        \Config\Services::resetSingle('throttler');
        parent::tearDown();
    }

    private function login(string $password = 'correct horse')
    {
        return $this->withBodyFormat('json')->post('api/auth/login', ['email' => 'admin@example.com', 'password' => $password]);
    }

    private function change(string $access, array $body)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$access}"])->withBodyFormat('json')
            ->post('api/account/security/password', $body);
    }

    public function testChangePasswordReturnsSessionAndSignsOutOtherDevices(): void
    {
        $old = json_decode($this->login()->getJSON(), true)['data'];
        $res = $this->change($old['access_token'], ['current_password' => 'correct horse', 'new_password' => 'a much longer secret phrase']);
        $res->assertStatus(200);
        $d = json_decode($res->getJSON(), true)['data'];
        foreach (['user', 'access_token', 'refresh_token', 'token_type', 'expires_in'] as $k) $this->assertArrayHasKey($k, $d);
        $this->assertSame('Bearer', $d['token_type']);
        $this->assertTrue($res->response()->hasCookie('aistudio_refresh'));
        $cookie = $res->response()->getCookie('aistudio_refresh');
        $this->assertTrue($cookie->isHTTPOnly());
        $this->assertSame($d['refresh_token'], $cookie->getValue());

        unset($_SERVER['ci_jwt_payload']);
        $this->withHeaders(['Authorization' => "Bearer {$old['access_token']}"])->get('api/auth/profile')->assertStatus(401);
        unset($_SERVER['ci_jwt_payload']);
        $this->withHeaders(['Authorization' => "Bearer {$d['access_token']}"])->get('api/auth/profile')->assertStatus(200);
        unset($_SERVER['ci_jwt_payload']);
        $this->login('a much longer secret phrase')->assertStatus(200);
    }

    public function testChangePasswordFieldErrors(): void
    {
        $access = json_decode($this->login()->getJSON(), true)['data']['access_token'];
        $res = $this->change($access, ['current_password' => 'wrong', 'new_password' => 'a much longer secret phrase']);
        $res->assertStatus(422);
        $this->assertArrayHasKey('current_password', json_decode($res->getJSON(), true)['data']);
        foreach (['short', 'admin@example.com', 'password1234'] as $bad) {
            unset($_SERVER['ci_jwt_payload']);
            $res = $this->change($access, ['current_password' => 'correct horse', 'new_password' => $bad]);
            $res->assertStatus(422);
            $this->assertArrayHasKey('new_password', json_decode($res->getJSON(), true)['data']);
        }
    }

    public function testChangePasswordIsRateLimited(): void
    {
        $access = json_decode($this->login()->getJSON(), true)['data']['access_token'];
        for ($i = 0; $i < 5; $i++) {
            unset($_SERVER['ci_jwt_payload']);
            $this->change($access, ['current_password' => 'nope', 'new_password' => 'x'])->assertStatus(422);
        }
        unset($_SERVER['ci_jwt_payload']);
        $this->change($access, ['current_password' => 'correct horse', 'new_password' => 'a much longer secret phrase'])->assertStatus(429);
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNotNull(PasswordPolicy::problem('elevenchars'));
        $this->assertNotNull(PasswordPolicy::problem('Admin@Example.com', 'admin@example.com'));
        $this->assertNotNull(PasswordPolicy::problem('PASSWORD1234'));
        $this->assertNull(PasswordPolicy::problem('violet river lantern', 'admin@example.com'));
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        $forge = \Config\Database::forge($this->DBGroup);
        $forge->dropTable('user_recovery_codes', true);
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER'], 'code_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'used_at' => ['type' => 'DATETIME', 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true]])->addKey('id', true)->createTable('user_recovery_codes');
        $totp   = new TotpService();
        $secret = $totp->generateSecret();
        $totp->enable(1, $secret, 0);
        $at   = new \ReflectionMethod($totp, 'at');
        $code = $at->invoke($totp, $secret, intdiv(time(), 30));
        $user = $this->db->table('users')->where('id', 1)->get()->getRowArray();
        $this->assertTrue($totp->check($user, $code));
        // A second request that read the row before the first one wrote it (stale totp_last_step).
        $this->assertFalse($totp->check($user, $code));
    }

    public function testProxyIpsParsing(): void
    {
        $this->assertSame(['10.0.1.200' => 'X-Forwarded-For', '192.168.5.0/24' => 'X-Real-IP'],
            \Config\App::parseProxyIPs(' 10.0.1.200=X-Forwarded-For, 192.168.5.0/24=X-Real-IP, junk, 1.2.3.4=bad header'));
    }

    public function testLocalStorageRootAllowlist(): void
    {
        class_exists(\App\Libraries\Storage\StorageDriver::class); // LocalDriver lives in that file
        $this->assertTrue(LocalDriver::allowedRoot('/srv/aistudio-storage/disk1'));
        $this->assertTrue(LocalDriver::allowedRoot(rtrim(WRITEPATH, '/') . '/storage'));
        $this->assertFalse(LocalDriver::allowedRoot('/srv/aistudio-storage/../../etc'));
        $this->assertFalse(LocalDriver::allowedRoot('/srv/aistudio-storage-evil'));
        $this->assertFalse(LocalDriver::allowedRoot('/home/user'));
        // The allowlist is enforced when a node is saved, not when an existing node is used: a node
        // saved before the allowlist (e.g. an NFS mount under /mnt) keeps working.
        $this->assertInstanceOf(LocalDriver::class, new LocalDriver(['path' => '/mnt/legacy-nas']));
        $this->expectException(\InvalidArgumentException::class);
        new LocalDriver(['path' => '/srv/aistudio-storage/../etc']);
    }

    public function testTrainingOutputIsCapped(): void
    {
        $forge = \Config\Database::forge($this->DBGroup);
        $forge->dropTable('training_jobs', true);
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'report_token_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true]])->addKey('id', true)->createTable('training_jobs');
        $this->db->table('training_jobs')->insert(['report_token_hash' => hash('sha256', 'tok')]);
        $id  = (int) $this->db->insertID();
        $dir = WRITEPATH . "training/{$id}/output/";
        $_SERVER['training.maxOutputGb'] = (string) (2048 / 1073741824); // 2 KB
        try {
            $put = fn (string $name, int $bytes, string $q = '') => $this->withHeaders(['Authorization' => 'Bearer tok'])
                ->withBody(str_repeat('x', $bytes))->call('put', "api/training/{$id}/output/{$name}{$q}");
            $put('a.bin', 1500)->assertStatus(201);
            $put('a.bin', 1800)->assertStatus(201);           // replacing a file does not count it twice
            $put('b.bin', 600)->assertStatus(413);
            $put('c.bin', 10, '?part=0&parts=20000')->assertStatus(413);
        } finally {
            unset($_SERVER['training.maxOutputGb']);
            foreach (glob($dir . '*') ?: [] as $f) @unlink($f);
            @rmdir($dir); @rmdir(dirname($dir));
        }
    }
}
