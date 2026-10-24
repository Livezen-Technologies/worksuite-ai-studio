<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiLogModel extends Model
{
    protected $table         = 'api_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'api_key_id', 'user_id', 'endpoint', 'model', 'server_id', 'method',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'processing_ms',
        'status', 'client_ip', 'country', 'error', 'created_at',
    ];

    /** Token usage for a key since a given datetime (for quota enforcement). */
    public function tokensSince(int $apiKeyId, string $since): int
    {
        $row = $this->selectSum('total_tokens')
            ->where('api_key_id', $apiKeyId)->where('created_at >=', $since)
            ->get()->getRowArray();
        return (int) ($row['total_tokens'] ?? 0);
    }

    /** Filtered, paginated log feed for the API Logs viewer. */
    public function feed(array $f, int $limit, int $offset): array
    {
        $b = $this->db->table('api_logs l')
            ->select('l.*, k.name AS key_name, s.name AS server_name')
            ->join('api_keys k', 'k.id = l.api_key_id', 'left')
            ->join('servers s', 's.id = l.server_id', 'left');

        if (! empty($f['api_key_id'])) $b->where('l.api_key_id', (int) $f['api_key_id']);
        if (! empty($f['model']))      $b->where('l.model', $f['model']);
        if (! empty($f['status']))     $b->where('l.status', (int) $f['status']);
        if (! empty($f['since']))      $b->where('l.created_at >=', $f['since']);

        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('l.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        return ['rows' => $rows, 'total' => $total];
    }
}
