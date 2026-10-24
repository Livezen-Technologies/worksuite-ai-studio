<?php

namespace App\Models;

use CodeIgniter\Model;

class RoleModel extends Model
{
    protected $table         = 'roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'label', 'description', 'is_system'];

    /** Roles with their permission-slug list and user counts (for the Roles page). */
    public function withCounts(): array
    {
        $roles = $this->orderBy('id', 'ASC')->findAll();
        foreach ($roles as &$r) {
            $r['user_count']  = $this->db->table('users')
                ->where('role_id', $r['id'])->where('deleted_at', null)->countAllResults();
            $rows = $this->db->table('role_permissions rp')
                ->select('p.name')->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $r['id'])->get()->getResultArray();
            $r['permissions'] = array_column($rows, 'name');
        }
        return $roles;
    }

    /** Replace a role's permission set with the given permission ids. */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();
        $rows = [];
        foreach (array_unique(array_map('intval', $permissionIds)) as $pid) {
            if ($pid > 0) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $pid];
            }
        }
        if ($rows) {
            $this->db->table('role_permissions')->insertBatch($rows);
        }
    }
}
