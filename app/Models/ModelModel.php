<?php

namespace App\Models;

use CodeIgniter\Model;

class ModelModel extends Model
{
    protected $table         = 'models';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields = [
        'server_id', 'name', 'family', 'parameter_size', 'quantization', 'format',
        'size_bytes', 'digest', 'context_length', 'status', 'is_default', 'modified_at', 'synced_at',
    ];

    /** Installed models joined with their node name + per-model config presence. */
    public function listWithServer(?int $serverId = null): array
    {
        $b = $this->db->table('models m')
            ->select('m.*, s.name AS server_name, s.status AS server_status, s.api_url')
            ->join('servers s', 's.id = m.server_id')
            ->where('s.deleted_at', null)
            ->orderBy('m.name', 'ASC');
        if ($serverId) {
            $b->where('m.server_id', $serverId);
        }
        return $b->get()->getResultArray();
    }
}
