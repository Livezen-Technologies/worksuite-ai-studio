<?php

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Staff SSO part 2: keeps local AI Studio accounts in step with WorkSuite Cloud.
 *
 * A WorkSuite Cloud admin is found by `wsc_sub`, else by email (any letter case) and then linked.
 * Unknown admins are created (active, no usable password). Only staff roles are accepted; `customer`
 * is never handed out this way. Local-only accounts are never touched unless their email matches.
 */
class WscAccountSync
{
    /** Role codes WorkSuite Cloud may grant (AuthSeeder slugs), with labels for the Cloud Admin role select. */
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'admin'       => 'Admin',
        'developer'   => 'Developer',
        'support'     => 'Support',
        'viewer'      => 'Viewer',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public static function acceptsRole(string $role): bool { return isset(self::ROLES[$role]); }

    /** The linked or email-matched user row (deleted users excluded), or null. */
    public function find(string $sub, string $email): ?array
    {
        $q = fn () => $this->db->table('users')->where('deleted_at', null);
        $row = $sub !== '' ? $q()->where('wsc_sub', $sub)->get()->getRowArray() : null;
        if (! $row && $email !== '') {
            $col = 'LOWER(' . $this->db->protectIdentifiers('email') . ') =';
            $row = $q()->where($col, strtolower($email))->groupStart()->where('wsc_sub', null)->orWhere('wsc_sub', $sub)->groupEnd()
                ->get()->getRowArray();
        }
        return $row ?: null;
    }

    /**
     * Link/create and set name + role. Re-enables an account only when WorkSuite Cloud disabled it;
     * a local suspension stays. Returns the user id.
     */
    public function upsert(string $sub, string $email, string $name, string $role): int
    {
        $email = strtolower(trim($email));
        if ($sub === '' || $email === '') throw new RuntimeException('The account has no subject or email.');
        if (! self::acceptsRole($role)) throw new RuntimeException('That role is not an AI Studio staff role.');
        $roleId = $this->db->table('roles')->select('id')->where('name', $role)->get()->getRow('id');
        if (! $roleId) throw new RuntimeException('That role does not exist in AI Studio.');

        $users = new UserModel();
        $row   = $this->find($sub, $email);
        $name  = trim($name) !== '' ? trim($name) : $email;
        if (! $row) {
            if ($this->emailTaken($email, 0)) throw new RuntimeException("{$email} is already linked to another WorkSuite Cloud account.");
            $this->db->table('users')->insert([
                'name' => $name, 'email' => $email, 'role_id' => (int) $roleId, 'status' => 'active',
                // No usable password: a random secret nobody knows. Sign-in is through WorkSuite Cloud.
                'password_hash'  => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'wsc_sub' => $sub, 'managed_by_wsc' => 1, 'wsc_disabled' => 0, 'token_version' => 0,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $this->db->insertID();
        }

        $set = ['wsc_sub' => $sub, 'managed_by_wsc' => 1, 'name' => $name, 'role_id' => (int) $roleId];
        if ($row['email'] !== $email && ! $this->emailTaken($email, (int) $row['id'])) $set['email'] = $email;
        if (! empty($row['wsc_disabled'])) $set += ['status' => 'active', 'wsc_disabled' => 0];
        $this->db->table('users')->where('id', $row['id'])->update($set + ['updated_at' => date('Y-m-d H:i:s')]);
        if ((int) $row['role_id'] !== (int) $roleId || isset($set['email']) || isset($set['status'])) {
            $users->revokeTokens((int) $row['id']);
        }
        return (int) $row['id'];
    }

    /** Suspend a linked/matched user (data kept) and end their sessions. Returns false when there's nobody to disable. */
    public function disable(string $sub, string $email): bool
    {
        $row = $this->find($sub, strtolower(trim($email)));
        if (! $row) return false;
        $set = ['wsc_sub' => $sub, 'managed_by_wsc' => 1, 'updated_at' => date('Y-m-d H:i:s')];
        // Already suspended here by a local admin: leave it a local suspension so a later upsert keeps it.
        if ($row['status'] !== 'suspended') $set += ['status' => 'suspended', 'wsc_disabled' => 1];
        $this->db->table('users')->where('id', $row['id'])->update($set);
        (new UserModel())->revokeTokens((int) $row['id']);
        return true;
    }

    /** Record a provisioning jti; false when it was already seen (a replay). Expired ones are pruned. */
    public function claimJti(string $jti, int $exp): bool
    {
        $t = $this->db->table('wsc_seen_jti');
        $t->where('expires_at <', date('Y-m-d H:i:s', time() - 60))->delete();
        if ($this->db->table('wsc_seen_jti')->where('jti', $jti)->countAllResults() > 0) return false;
        try {
            return (bool) $this->db->table('wsc_seen_jti')->insert(['jti' => $jti, 'expires_at' => date('Y-m-d H:i:s', $exp)]);
        } catch (\Throwable) {
            return false; // a concurrent duplicate lost the primary-key race
        }
    }

    private function emailTaken(string $email, int $exceptId): bool
    {
        $col = 'LOWER(' . $this->db->protectIdentifiers('email') . ') =';
        return $this->db->table('users')->where($col, $email)->where('id !=', $exceptId)->countAllResults() > 0;
    }
}
