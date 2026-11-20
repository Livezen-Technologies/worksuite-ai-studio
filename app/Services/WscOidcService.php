<?php

namespace App\Services;

use RuntimeException;

/**
 * "Sign in with WorkSuite Cloud" for AI Studio staff (staff SSO contract, part 3).
 *
 * OpenID Connect authorization code + PKCE (S256) against OneAuth, with the confidential client
 * secret. The id_token is verified here against the issuer's JWKS (RS256): signature, iss, aud,
 * exp/iat and nonce. Configured from the environment only; with any value missing the feature is off.
 *
 *   WSC_OIDC_ISSUER, WSC_OIDC_CLIENT_ID, WSC_OIDC_CLIENT_SECRET, WSC_OIDC_REDIRECT_URI
 *
 * HTTP goes through $http (fn(string $method, string $url, array $form = [], array $headers = []): array{int, string})
 * so tests can stand in for the IdP.
 */
class WscOidcService
{
    /** @var callable */
    private $http;

    public function __construct(
        private ?array $config = null,
        ?callable $http = null,
    ) {
        $this->config ??= [
            'issuer'        => rtrim((string) env('WSC_OIDC_ISSUER', ''), '/'),
            'client_id'     => (string) env('WSC_OIDC_CLIENT_ID', ''),
            'client_secret' => (string) env('WSC_OIDC_CLIENT_SECRET', ''),
            'redirect_uri'  => (string) env('WSC_OIDC_REDIRECT_URI', ''),
        ];
        $this->http = $http ?? [self::class, 'curl'];
    }

    public function enabled(): bool
    {
        foreach (['issuer', 'client_id', 'client_secret', 'redirect_uri'] as $k) {
            if (trim((string) ($this->config[$k] ?? '')) === '') return false;
        }
        return true;
    }

    public function issuer(): string { return (string) $this->config['issuer']; }

    /** PKCE verifier/challenge pair (RFC 7636, S256). */
    public static function pkce(): array
    {
        $verifier = self::b64u(random_bytes(48));
        return [$verifier, self::b64u(hash('sha256', $verifier, true))];
    }

    public static function random(): string { return self::b64u(random_bytes(24)); }

    public function authorizeUrl(string $state, string $nonce, string $challenge, bool $silent = false): string
    {
        $q = [
            'response_type'         => 'code',
            'client_id'             => $this->config['client_id'],
            'redirect_uri'          => $this->config['redirect_uri'],
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if ($silent) $q['prompt'] = 'none';
        return $this->discovery()['authorization_endpoint'] . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange the code; returns the verified id_token claims. */
    public function exchange(string $code, string $verifier, string $nonce): array
    {
        [$status, $body] = ($this->http)('POST', $this->discovery()['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config['redirect_uri'],
            'code_verifier' => $verifier,
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);
        $json = json_decode((string) $body, true);
        if ($status !== 200 || ! is_array($json) || empty($json['id_token'])) {
            throw new RuntimeException('WorkSuite Cloud did not complete the sign-in. Please try again.');
        }
        return $this->verifyIdToken((string) $json['id_token'], $nonce);
    }

    public function verifyIdToken(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new RuntimeException('The sign-in token is malformed.');
        $header = json_decode(self::b64uDecode($parts[0]), true);
        $claims = json_decode(self::b64uDecode($parts[1]), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('The sign-in token is malformed.');
        }

        $key = $this->jwk((string) ($header['kid'] ?? ''));
        $ok  = $key && openssl_verify("{$parts[0]}.{$parts[1]}", self::b64uDecode($parts[2]), $key, OPENSSL_ALGO_SHA256) === 1;
        if (! $ok) throw new RuntimeException('The sign-in token signature is not valid.');

        $now = time();
        $aud = (array) ($claims['aud'] ?? []);
        if (($claims['iss'] ?? '') !== $this->issuer()) throw new RuntimeException('The sign-in token has the wrong issuer.');
        if (! in_array($this->config['client_id'], $aud, true)) throw new RuntimeException('The sign-in token is not for AI Studio.');
        if (count($aud) > 1 && ($claims['azp'] ?? '') !== $this->config['client_id']) throw new RuntimeException('The sign-in token is not for AI Studio.');
        if ((int) ($claims['exp'] ?? 0) < $now - 60) throw new RuntimeException('The sign-in token has expired.');
        if ((int) ($claims['iat'] ?? 0) > $now + 300) throw new RuntimeException('The sign-in token is not valid yet.');
        if (! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) throw new RuntimeException('The sign-in token does not match this sign-in.');
        return $claims;
    }

    /** Discovery document, cached for an hour. Tries {issuer}/.well-known then {issuer}/oauth/.well-known. */
    public function discovery(): array
    {
        $ck = 'wsc_oidc_disc_' . md5($this->issuer());
        if (is_array($d = cache($ck))) return $d;
        foreach (['/.well-known/openid-configuration', '/oauth/.well-known/openid-configuration'] as $path) {
            [$status, $body] = ($this->http)('GET', $this->issuer() . $path);
            $d = json_decode((string) $body, true);
            if ($status === 200 && is_array($d) && ($d['issuer'] ?? '') === $this->issuer()
                && ! empty($d['authorization_endpoint']) && ! empty($d['token_endpoint']) && ! empty($d['jwks_uri'])) {
                cache()->save($ck, $d, 3600);
                return $d;
            }
        }
        throw new RuntimeException('WorkSuite Cloud sign-in is unavailable right now.');
    }

    /** Public key for $kid from the JWKS (cached 10 min; refetched once when the kid is unknown, for rotation). */
    private function jwk(string $kid)
    {
        $ck = 'wsc_oidc_jwks_' . md5($this->issuer());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $keys = $attempt === 0 ? cache($ck) : null;
            if (! is_array($keys)) {
                [$status, $body] = ($this->http)('GET', $this->discovery()['jwks_uri']);
                $keys = $status === 200 ? (json_decode((string) $body, true)['keys'] ?? null) : null;
                if (! is_array($keys)) return null;
                cache()->save($ck, $keys, 600);
            }
            foreach ($keys as $k) {
                if (($k['kty'] ?? '') === 'RSA' && ($kid === '' || ($k['kid'] ?? '') === $kid) && ! empty($k['n']) && ! empty($k['e'])) {
                    return openssl_pkey_get_public(self::rsaPem(self::b64uDecode($k['n']), self::b64uDecode($k['e']))) ?: null;
                }
            }
        }
        return null;
    }

    /** SubjectPublicKeyInfo PEM from an RSA modulus and exponent. */
    public static function rsaPem(string $n, string $e): string
    {
        $int = static function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '' || ord($b[0]) > 0x7f) $b = "\0" . $b;
            return "\x02" . self::derLen(strlen($b)) . $b;
        };
        $rsa  = $int($n) . $int($e);
        $rsa  = "\x30" . self::derLen(strlen($rsa)) . $rsa;
        $bits = "\x03" . self::derLen(strlen($rsa) + 1) . "\0" . $rsa;
        $alg  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = "\x30" . self::derLen(strlen($alg . $bits)) . $alg . $bits;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $l): string
    {
        if ($l < 0x80) return chr($l);
        $s = ltrim(pack('N', $l), "\0");
        return chr(0x80 | strlen($s)) . $s;
    }

    public static function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** @return array{int, string} */
    public static function curl(string $method, string $url, array $form = [], array $headers = []): array
    {
        if (! str_starts_with($url, 'https://') && ENVIRONMENT === 'production') {
            throw new RuntimeException('WorkSuite Cloud sign-in must use HTTPS.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, is_string($body) ? $body : ''];
    }
}
