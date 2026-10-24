<?php

namespace App\Services;

/**
 * JwtService — self-contained HMAC-SHA256 JWT (no external deps).
 *
 * Issues short-lived access tokens and longer-lived refresh tokens for the
 * OllamaHub admin/dashboard API. Adapted from the Livezen POS pattern.
 */
class JwtService
{
    protected string $secret;
    protected int $accessTtl  = 3600;     // 1 hour
    protected int $refreshTtl = 1209600;  // 14 days

    public function __construct()
    {
        $this->secret = (string) env('JWT_SECRET', 'ollamahub-dev-secret-change-me-32-chars-minimum');
    }

    /**
     * Access token. $user must contain id, email, name, role (role slug).
     */
    public function generateAccessToken(array $user): string
    {
        return $this->encode([
            'sub'   => (int) $user['id'],
            'email' => $user['email'] ?? '',
            'name'  => $user['name'] ?? '',
            'role'  => $user['role'] ?? 'viewer',
            'type'  => 'access',
            'iat'   => time(),
            'exp'   => time() + $this->accessTtl,
        ]);
    }

    public function generateRefreshToken(int $userId): string
    {
        return $this->encode([
            'sub'  => $userId,
            'type' => 'refresh',
            'iat'  => time(),
            'exp'  => time() + $this->refreshTtl,
        ]);
    }

    public function accessTtl(): int
    {
        return $this->accessTtl;
    }

    /** Validate + decode. Returns payload array, or null if invalid/expired. */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $sig] = $parts;

        $expected = $this->base64UrlEncode(hash_hmac('sha256', "{$h}.{$p}", $this->secret, true));
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($p), true);
        if (! is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    protected function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body   = $this->base64UrlEncode(json_encode($payload));
        $sig    = $this->base64UrlEncode(hash_hmac('sha256', "{$header}.{$body}", $this->secret, true));
        return "{$header}.{$body}.{$sig}";
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
