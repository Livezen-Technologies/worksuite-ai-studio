<?php

namespace App\Models;

use CodeIgniter\Model;

class ServerHealthModel extends Model
{
    protected $table         = 'server_health';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'server_id', 'status', 'health_score', 'latency_ms',
        'models_total', 'models_running', 'error', 'checked_at',
    ];

    /** Recent probe history for a server (newest first). */
    public function recent(int $serverId, int $limit = 50): array
    {
        return $this->where('server_id', $serverId)
            ->orderBy('id', 'DESC')->limit($limit)->find();
    }
}
