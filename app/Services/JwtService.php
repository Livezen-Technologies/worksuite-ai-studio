<?php

namespace App\Services;

/**
 * JwtService — self-contained HMAC-SHA256 JWT (no external deps).
 *
 * Issues short-lived access tokens and longer-lived refresh tokens for the
 * WorkSuite AI Studio admin/dashboard API. Adapted from the Livezen POS pattern.
 */
class JwtService
{
    protected string $secret;
    protected int $accessTtl  = 3600;     // 1 hour
    protected int $refreshTtl = 1209600;  // 14 days

    /** Development-only fallback. Never accepted in production. */
    public const DEV_SECRET = 'ollamahub-dev-secret-change-me-32-chars-minimum';

    public function __construct()
    {
        $this->secret = self::secret();
    }

    /**
     * The signing secret (also the root of the TOTP encryption key). In production a missing, short
     * or default JWT_SECRET is refused: anyone could otherwise forge an admin token with the
     * default from this repository.
     */
    public static function secret(): string
    {
        $secret = (string) env('JWT_SECRET', '');
        if (strlen($secret) >= 32 && $secret !== self::DEV_SECRET) {
            return $secret;
        }
        if (ENVIRONMENT === 'production') {
            throw new \RuntimeException('JWT_SECRET must be set to a random value of at least 32 characters (e.g. `openssl rand -hex 32`).');
        }
        return $secret !== '' ? $secret : self::DEV_SECRET;
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
            'tv'    => (int) ($user['token_version'] ?? 0),
            'type'  => 'access',
            'iat'   => time(),
            'exp'   => time() + $this->accessTtl,
        ]);
    }

    /** $tokenVersion: users.token_version at issue; bumping it revokes every token issued before. */
    public function generateRefreshToken(int $userId, int $tokenVersion = 0): string
    {
        return $this->encode([
            'sub'  => $userId,
            'tv'   => $tokenVersion,
            'type' => 'refresh',
            'iat'  => time(),
            'exp'  => time() + $this->refreshTtl,
        ]);
    }

    /**
     * Short-lived token for one sign-in step (type = $purpose): a pending 2FA challenge, a TOTP secret
     * awaiting confirmation, or a WebAuthn challenge. $claims are merged into the payload.
     */
    public function generateStepToken(string $purpose, array $claims, int $ttl = 300): string
    {
        return $this->encode([
            'type' => $purpose,
            'iat'  => time(),
            'exp'  => time() + $ttl,
        ] + $claims);
    }

    /** decode() that also checks the token type. */
    public function decodeStep(string $token, string $purpose): ?array
    {
        $payload = $token !== '' ? $this->decode($token) : null;
        return $payload && ($payload['type'] ?? '') === $purpose ? $payload : null;
    }

    public function accessTtl(): int
    {
        return $this->accessTtl;
    }

    public function refreshTtl(): int
    {
        return $this->refreshTtl;
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
