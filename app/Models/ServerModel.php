<?php

namespace App\Models;

use CodeIgniter\Model;

class ServerModel extends Model
{
    protected $table          = 'servers';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'name', 'description', 'kind', 'hostname', 'ip_address', 'api_url', 'port', 'auth_token',
        'gpu_model', 'cuda_version', 'cpu_cores', 'ram_gb', 'storage_gb', 'os',
        'ollama_version', 'is_local', 'status', 'health_score', 'last_heartbeat_at',
    ];

    protected $validationRules = [
        'name'    => 'required|min_length[2]|max_length[120]',
        'api_url' => 'required|max_length[255]',
    ];

    /** Never leak the node auth token to the API surface. */
    public function publicRow(array $row): array
    {
        $row['has_token'] = ! empty($row['auth_token'] ?? null);
        unset($row['auth_token'], $row['deleted_at']);
        return $row;
    }
}
