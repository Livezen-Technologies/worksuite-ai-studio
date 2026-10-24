<?php

namespace App\Models;

use CodeIgniter\Model;

class PermissionModel extends Model
{
    protected $table         = 'permissions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['name', 'label', 'module'];

    /** All permissions grouped by module — drives the RBAC matrix UI. */
    public function grouped(): array
    {
        $all = $this->orderBy('module', 'ASC')->orderBy('id', 'ASC')->findAll();
        $out = [];
        foreach ($all as $p) {
            $out[$p['module']][] = $p;
        }
        return $out;
    }
}
