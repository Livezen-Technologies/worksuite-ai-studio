<?php

namespace App\Services;

/**
 * ApiTokenService — short-lived JWT access tokens bound to an API key.
 *
 * A token carries the key id (kid), the key's token_version (kv) and a scope subset. It is
 * accepted by the /api/v1 gateway as `Authorization: Bearer <jwt>`. Rotating or revoking the
 * key bumps token_version, which invalidates every token issued before.
 */
class ApiTokenService
{
    public const TYPE        = 'api_access';
    public const DEFAULT_TTL = 3600;        // 1 hour
    public const MAX_TTL     = 2592000;     // 30 days

    /** @return array{access_token:string, token_type:string, expires_in:int, expires_at:string, scope:string} */
    public function issue(array $key, ?array $scopes = null, ?int $ttl = null): array
    {
        $keyScopes = array_values(array_filter(explode(',', (string) ($key['scopes'] ?? ''))));
        $granted   = $scopes ? array_values(array_intersect($scopes, $keyScopes)) : $keyScopes;
        $ttl       = max(60, min(self::MAX_TTL, $ttl ?: self::DEFAULT_TTL));
        if (! empty($key['expires_at'])) { // never outlive the key
            $ttl = max(1, min($ttl, strtotime($key['expires_at']) - time()));
        }
        $token = (new JwtService())->generateStepToken(self::TYPE, [
            'kid'    => (int) $key['id'],
            'kv'     => (int) ($key['token_version'] ?? 0),
            'sub'    => isset($key['user_id']) ? (int) $key['user_id'] : null,
            'scopes' => $granted,
        ], $ttl);
        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => $ttl,
            'expires_at'   => date('c', time() + $ttl),
            'scope'        => implode(' ', $granted),
        ];
    }

    /** Decode a token; null when invalid, expired or not an API access token. */
    public function decode(string $token): ?array
    {
        return (new JwtService())->decodeStep($token, self::TYPE);
    }
}
