<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'name', 'email', 'password_hash', 'role_id', 'avatar', 'status', 'last_login_at',
    ];

    protected $validationRules = [
        'name'  => 'required|min_length[2]|max_length[120]',
        'email' => 'required|valid_email|max_length[191]',
    ];

    /** Find an active user by email (includes password_hash for verification). */
    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->where('status', 'active')->first();
    }

    /**
     * User with role slug + flat permission slug list. Used for /auth/profile and
     * to mint the JWT (role) at login. Returns null when not found.
     */
    public function withRoleAndPermissions(int $id): ?array
    {
        $user = $this->select('users.*, roles.name AS role, roles.label AS role_label')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->where('users.id', $id)
            ->first();

        if (! $user) {
            return null;
        }
        unset($user['password_hash']);
        $user['permissions'] = $this->permissionsForRole((int) ($user['role_id'] ?? 0));
        return $user;
    }

    /** Flat list of permission slugs granted to a role. */
    public function permissionsForRole(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        $rows = $this->db->table('role_permissions rp')
            ->select('p.name')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get()->getResultArray();
        return array_column($rows, 'name');
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}
