<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiKeyModel extends Model
{
    protected $table         = 'api_keys';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'user_id', 'api_key', 'secret_hash', 'secret_prefix', 'scopes',
        'allowed_models', 'allowed_ips', 'daily_token_limit', 'monthly_token_limit',
        'rate_limit_per_min', 'status', 'expires_at', 'last_used_at',
    ];

    /** Generate a fresh key id + secret. Returns [key, secret, secret_hash, prefix]. */
    public function mint(): array
    {
        $key    = 'ohub_' . bin2hex(random_bytes(12));
        $secret = 'sk_' . bin2hex(random_bytes(20));
        return [
            'key'    => $key,
            'secret' => $secret,
            'hash'   => password_hash($secret, PASSWORD_DEFAULT),
            'prefix' => substr($secret, 0, 9),
        ];
    }

    /** Public-safe representation (never expose the secret hash). */
    public function publicRow(array $row): array
    {
        unset($row['secret_hash']);
        $row['allowed_models'] = $row['allowed_models'] ? json_decode($row['allowed_models'], true) : null;
        $row['allowed_ips']    = $row['allowed_ips'] ? json_decode($row['allowed_ips'], true) : null;
        $row['scopes']         = $row['scopes'] ? explode(',', $row['scopes']) : [];
        return $row;
    }

    public function findByKey(string $key): ?array
    {
        return $this->where('api_key', $key)->first();
    }
}
