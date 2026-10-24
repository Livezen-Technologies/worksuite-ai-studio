<?php

namespace App\Controllers\Api;

use App\Models\RoleModel;
use App\Models\PermissionModel;

/**
 * Roles + permission matrix.
 *
 *   GET    /api/roles               roles with permissions + user counts (roles.view)
 *   GET    /api/roles/permissions   permission catalog grouped by module
 *   POST   /api/roles               create custom role                  (roles.manage)
 *   PUT    /api/roles/{id}          update label/desc + permission set   (roles.manage)
 *   DELETE /api/roles/{id}          delete (non-system only)             (roles.manage)
 */
class RolesController extends BaseApiController
{
    private function roles(): RoleModel { return new RoleModel(); }

    public function index()
    {
        if ($r = $this->requirePermission('roles.view')) return $r;
        return $this->ok($this->roles()->withCounts());
    }

    public function permissions()
    {
        if ($r = $this->requirePermission('roles.view')) return $r;
        return $this->ok((new PermissionModel())->grouped());
    }

    public function create()
    {
        if ($r = $this->requirePermission('roles.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, ['label' => 'required|min_length[2]|max_length[100]'])) {
            return $this->failure('A role name is required.', 422, $errors);
        }
        $slug = $this->slug($in['name'] ?? $in['label']);
        if ($this->roles()->where('name', $slug)->first()) {
            return $this->failure('A role with that name already exists.', 422);
        }
        $id = $this->roles()->insert([
            'name' => $slug, 'label' => $in['label'],
            'description' => $in['description'] ?? null, 'is_system' => 0,
        ], true);
        if (! empty($in['permission_ids']) && is_array($in['permission_ids'])) {
            $this->roles()->syncPermissions($id, $in['permission_ids']);
        }
        return $this->ok($this->roles()->find($id), 'Role created.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('roles.manage')) return $r;
        $role = $this->roles()->find((int) $id);
        if (! $role) return $this->failure('Role not found.', 404);

        $in = $this->input();
        $data = [];
        foreach (['label', 'description'] as $f) {
            if (isset($in[$f])) $data[$f] = $in[$f];
        }
        if ($data) $this->roles()->update((int) $id, $data);

        if (array_key_exists('permission_ids', $in) && is_array($in['permission_ids'])) {
            // super_admin always keeps every permission.
            if ($role['name'] !== 'super_admin') {
                $this->roles()->syncPermissions((int) $id, $in['permission_ids']);
            }
        }
        return $this->ok(null, 'Role updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('roles.manage')) return $r;
        $role = $this->roles()->find((int) $id);
        if (! $role) return $this->failure('Role not found.', 404);
        if ((int) $role['is_system'] === 1) return $this->failure('System roles cannot be deleted.', 422);

        $inUse = \Config\Database::connect()->table('users')->where('role_id', $id)->where('deleted_at', null)->countAllResults();
        if ($inUse > 0) return $this->failure("This role is assigned to {$inUse} user(s).", 422);

        $this->roles()->delete((int) $id);
        return $this->ok(null, 'Role deleted.');
    }

    private function slug(string $s): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($s)));
    }
}
