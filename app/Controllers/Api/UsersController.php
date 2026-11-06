<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Models\RoleModel;

/**
 * User management.
 *
 *   GET    /api/users        list (with role)                 (users.view)
 *   POST   /api/users        create                           (users.manage)
 *   PUT    /api/users/{id}   update (optional password)       (users.manage)
 *   DELETE /api/users/{id}   delete (guards self + last admin)(users.manage)
 */
class UsersController extends BaseApiController
{
    private function users(): UserModel { return new UserModel(); }

    private const STATUSES = ['active', 'suspended'];

    private function role(int $id): ?array
    {
        return $id > 0 ? ((new RoleModel())->find($id) ?: null) : null;
    }

    /** Why the caller may not give someone this role, or null when they may. */
    private function roleProblem(int $roleId): ?string
    {
        $role = $this->role($roleId);
        if (! $role) return 'That role does not exist.';
        return $this->withinMyPrivileges($role) ? null : 'You cannot assign a role with more access than your own.';
    }

    /** Demoting or suspending the only active super admin would lock everyone out of administration. */
    private function wouldRemoveLastSuperAdmin(array $row, int $newRoleId, string $newStatus): bool
    {
        $super = (new RoleModel())->where('name', 'super_admin')->first();
        if (! $super || (int) $row['role_id'] !== (int) $super['id'] || $row['status'] !== 'active') return false;
        if ($newRoleId === (int) $super['id'] && $newStatus === 'active') return false;
        $others = $this->users()->where('role_id', $super['id'])->where('status', 'active')->where('id !=', (int) $row['id'])->countAllResults();
        return $others === 0;
    }

    public function index()
    {
        if ($r = $this->requirePermission('users.view')) return $r;
        $rows = $this->users()->builder()
            ->select('users.id, users.name, users.email, users.status, users.role_id, users.avatar, users.last_login_at, users.created_at, users.two_factor_required, users.totp_enabled_at, roles.name AS role, roles.label AS role_label')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->where('users.deleted_at', null)
            ->orderBy('users.id', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function create()
    {
        if ($r = $this->requirePermission('users.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, [
            'name'     => 'required|min_length[2]|max_length[120]',
            'email'    => 'required|valid_email|is_unique[users.email]',
            'password' => 'required|min_length[8]',
            'role_id'  => 'required|is_natural_no_zero',
        ])) {
            return $this->failure('Please check the fields.', 422, $errors);
        }
        if ($why = $this->roleProblem((int) $in['role_id'])) return $this->failure($why, 403);
        if (isset($in['status']) && ! in_array($in['status'], self::STATUSES, true)) {
            return $this->failure('Status must be active or suspended.', 422, ['status' => 'Invalid status.']);
        }

        $m  = $this->users();
        $id = $m->insert([
            'name' => $in['name'], 'email' => strtolower(trim($in['email'])),
            'password_hash' => $m->hash($in['password']),
            'role_id' => (int) $in['role_id'], 'status' => $in['status'] ?? 'active',
            'two_factor_required' => ! empty($in['two_factor_required']) ? 1 : 0,
        ], true);
        return $this->ok($this->one($id), 'User created.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('users.manage')) return $r;
        $m   = $this->users();
        $row = $m->find((int) $id);
        if (! $row) return $this->failure('User not found.', 404);
        // You cannot edit (reset the password of, suspend…) someone more powerful than you.
        if (! $this->withinMyPrivileges($this->role((int) $row['role_id']))) {
            return $this->failure('You cannot change a user whose role is above yours.', 403);
        }

        $in   = $this->input();
        $data = [];
        foreach (['name', 'role_id', 'status'] as $f) {
            if (isset($in[$f])) $data[$f] = $in[$f];
        }
        if (isset($data['role_id']) && (int) $data['role_id'] !== (int) $row['role_id']
            && ($why = $this->roleProblem((int) $data['role_id']))) {
            return $this->failure($why, 403);
        }
        if (isset($data['status']) && ! in_array($data['status'], self::STATUSES, true)) {
            return $this->failure('Status must be active or suspended.', 422, ['status' => 'Invalid status.']);
        }
        if ($this->wouldRemoveLastSuperAdmin($row, (int) ($data['role_id'] ?? $row['role_id']), (string) ($data['status'] ?? $row['status']))) {
            return $this->failure('This is the last active super admin. Make someone else a super admin first.', 422);
        }
        if (! empty($in['email']) && strtolower($in['email']) !== $row['email']) {
            if ($this->users()->where('email', strtolower($in['email']))->where('id !=', (int) $id)->first()) {
                return $this->failure('That email is already in use.', 422);
            }
            $data['email'] = strtolower(trim($in['email']));
        }
        if (! empty($in['password'])) {
            if (strlen($in['password']) < 8) return $this->failure('Password must be at least 8 characters.', 422);
            $data['password_hash'] = $m->hash($in['password']);
        }
        if (isset($in['two_factor_required'])) $data['two_factor_required'] = $in['two_factor_required'] ? 1 : 0;
        if ($data) $m->update((int) $id, $data);
        // Lost phone: clear their authenticator and passkeys so they can sign in with a password and set up again.
        if (! empty($in['reset_sign_in_methods'])) {
            (new \App\Services\TotpService())->disable((int) $id);
            \Config\Database::connect()->table('user_passkeys')->where('user_id', (int) $id)->delete();
        }
        // New password, email, role, status or 2FA requirement, or a sign-in reset: end the
        // user's existing sessions so the change applies to every device at once.
        $roleChanged = isset($data['role_id']) && (int) $data['role_id'] !== (int) $row['role_id'];
        $statusChanged = isset($data['status']) && $data['status'] !== $row['status'];
        $twoFactorNowRequired = isset($data['two_factor_required']) && $data['two_factor_required'] && empty($row['two_factor_required']);
        if (isset($data['password_hash']) || isset($data['email']) || $roleChanged || $statusChanged
            || $twoFactorNowRequired || ! empty($in['reset_sign_in_methods'])) {
            $m->revokeTokens((int) $id);
        }
        return $this->ok($this->one((int) $id), 'User updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('users.manage')) return $r;
        $id = (int) $id;
        if ($id === $this->userId()) return $this->failure('You cannot delete your own account.', 422);

        $m   = $this->users();
        $row = $m->find($id);
        if (! $row) return $this->failure('User not found.', 404);
        if (! $this->withinMyPrivileges($this->role((int) $row['role_id']))) {
            return $this->failure('You cannot delete a user whose role is above yours.', 403);
        }

        // Don't allow removing the last active super admin.
        $superRole = (new RoleModel())->where('name', 'super_admin')->first();
        if ($superRole && (int) $row['role_id'] === (int) $superRole['id']) {
            $count = $m->where('role_id', $superRole['id'])->where('deleted_at', null)->countAllResults();
            if ($count <= 1) return $this->failure('Cannot delete the last super admin.', 422);
        }
        $m->delete($id);
        return $this->ok(null, 'User deleted.');
    }

    private function one(int $id): array
    {
        return $this->users()->builder()
            ->select('users.id, users.name, users.email, users.status, users.role_id, users.last_login_at, users.two_factor_required, users.totp_enabled_at, roles.name AS role, roles.label AS role_label')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->where('users.id', $id)->get()->getRowArray() ?? [];
    }
}
