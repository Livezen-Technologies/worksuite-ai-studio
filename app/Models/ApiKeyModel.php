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
        'key_type', 'application', 'organization', 'description', 'rotation_days', 'rotated_at',
        'revoked_at', 'token_version', 'admin_locked',
        'previous_secret_hash', 'previous_secret_expires_at', 'rotation_enforce', 'rotation_notified_at',
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
        unset($row['secret_hash'], $row['previous_secret_hash']);
        $row['previous_secret_valid_until'] = (! empty($row['previous_secret_expires_at']) && strtotime($row['previous_secret_expires_at']) > time()) ? $row['previous_secret_expires_at'] : null;
        unset($row['previous_secret_expires_at']);
        $row['allowed_models'] = $row['allowed_models'] ? json_decode($row['allowed_models'], true) : null;
        $row['allowed_ips']    = $row['allowed_ips'] ? json_decode($row['allowed_ips'], true) : null;
        $row['scopes']         = $row['scopes'] ? explode(',', $row['scopes']) : [];
        $row['state']          = self::state($row);
        $row['rotation_due']   = self::rotationDue($row);
        return $row;
    }

    /** Effective status: revoked / disabled / expired / active. */
    public static function state(array $row): string
    {
        if (! empty($row['revoked_at']) || ($row['status'] ?? '') === 'revoked') return 'revoked';
        if (($row['status'] ?? 'active') !== 'active') return (string) $row['status'];
        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return 'expired';
        return 'active';
    }

    /** True when a rotation policy is set and the secret is older than it. */
    public static function rotationDue(array $row): bool
    {
        $days = (int) ($row['rotation_days'] ?? 0);
        if ($days <= 0) return false;
        $since = $row['rotated_at'] ?? null ?: ($row['created_at'] ?? null);
        return $since !== null && strtotime($since) < time() - $days * 86400;
    }

    /** Does $secret open this key: its current secret, or the previous one while its grace period lasts? */
    public static function secretMatches(array $row, string $secret): bool
    {
        if (password_verify($secret, (string) $row['secret_hash'])) return true;
        return ! empty($row['previous_secret_hash']) && ! empty($row['previous_secret_expires_at'])
            && strtotime($row['previous_secret_expires_at']) > time() && password_verify($secret, (string) $row['previous_secret_hash']);
    }

    public function findByKey(string $key): ?array
    {
        return $this->where('api_key', $key)->first();
    }
}
