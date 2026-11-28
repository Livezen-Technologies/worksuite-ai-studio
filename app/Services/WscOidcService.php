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
 * SSO Phase B (moving to https://auth.worksuitecloud.com):
 *   WSC_OIDC_ISSUER            the move switch; every endpoint comes from that issuer's discovery document,
 *                              and discovery/JWKS caches are keyed by issuer.
 *   WSC_OIDC_ACCEPTED_ISSUERS  issuers accepted on provisioning events and back-channel logout tokens
 *                              (comma list). Unset: both WorkSuite issuers when WSC_OIDC_ISSUER is one of
 *                              them, else only WSC_OIDC_ISSUER.
 *   WSC_SSO_POLICY             local | oneauth_optional (default) | oneauth_preferred | oneauth_required
 *   WSC_SSO_BREAKGLASS         emails that keep local sign-in under oneauth_required (comma list)
 *
 * HTTP goes through $http (fn(string $method, string $url, array $form = [], array $headers = []): array{int, string})
 * so tests can stand in for the IdP.
 */
class WscOidcService
{
    /** The two WorkSuite issuers: the account issuer (today) and the auth issuer (Phase B). */
    public const WORKSUITE_ISSUERS = ['https://account.worksuitecloud.com/oauth', 'https://auth.worksuitecloud.com'];

    public const POLICIES = ['local', 'oneauth_optional', 'oneauth_preferred', 'oneauth_required'];

    public const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

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
        // Phase B settings; an injected config (tests) gets the defaults, not the environment.
        $fromEnv = $config === null;
        $this->config += [
            'accepted_issuers' => $fromEnv ? (string) env('WSC_OIDC_ACCEPTED_ISSUERS', '') : '',
            'policy'           => $fromEnv ? (string) env('WSC_SSO_POLICY', '') : '',
            'breakglass'       => $fromEnv ? (string) env('WSC_SSO_BREAKGLASS', '') : '',
        ];
        $this->config['issuer'] = rtrim((string) $this->config['issuer'], '/');
        $this->http = $http ?? [self::class, 'curl'];
    }

    /**
     * Issuers accepted on provisioning events and logout tokens. Provisioning events are signed with the
     * account issuer whichever issuer the app signs in with, so a moved app must keep accepting it.
     *
     * @return list<string>
     */
    public function acceptedIssuers(): array
    {
        $raw = $this->config['accepted_issuers'];
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $list = array_values(array_unique(array_filter(array_map(static fn ($i) => rtrim(trim((string) $i), '/'), $list))));
        if ($list) return $list;
        return in_array($this->issuer(), self::WORKSUITE_ISSUERS, true) ? self::WORKSUITE_ISSUERS : [$this->issuer()];
    }

    /**
     * WSC_SSO_POLICY as it applies now: `local` when OneAuth isn't configured, `oneauth_optional` (today's
     * behaviour) when unset or unknown (an unknown value is logged).
     */
    public function policy(): string
    {
        if (! $this->enabled()) return 'local';
        $p = strtolower(trim((string) $this->config['policy']));
        if ($p === '') return 'oneauth_optional';
        if (! in_array($p, self::POLICIES, true)) {
            log_message('warning', 'WSC_SSO_POLICY "{p}" is not one of ' . implode(', ', self::POLICIES) . '; using oneauth_optional.', ['p' => $p]);
            return 'oneauth_optional';
        }
        return $p;
    }

    /** The WorkSuite Cloud button, silent sign-in and the start/callback routes are on. */
    public function signInOffered(): bool { return $this->policy() !== 'local'; }

    /**
     * Under oneauth_required the server refuses local (password, passkey) sign-in, except for break-glass
     * accounts: those listed in WSC_SSO_BREAKGLASS and local accounts never linked to OneAuth (no wsc_sub).
     */
    public function refusesLocalSignIn(array $user): bool
    {
        if ($this->policy() !== 'oneauth_required') return false;
        if (trim((string) ($user['wsc_sub'] ?? '')) === '') return false;
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $raw = $this->config['breakglass'];
        $list = array_map(static fn ($e) => strtolower(trim((string) $e)), is_array($raw) ? $raw : explode(',', (string) $raw));
        return ! in_array($email, array_filter($list), true);
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

    public function authorizeUrl(string $state, string $nonce, string $challenge, bool $silent = false, ?string $issuer = null): string
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
        return $this->discovery($issuer)['authorization_endpoint'] . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code at the token endpoint of the issuer the flow started under; returns the verified
     * id_token claims (whose iss must be that issuer).
     */
    public function exchange(string $code, string $verifier, string $nonce, ?string $issuer = null): array
    {
        $issuer ??= $this->issuer();
        [$status, $body] = ($this->http)('POST', $this->discovery($issuer)['token_endpoint'], [
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
        return $this->verifyIdToken((string) $json['id_token'], $nonce, $issuer);
    }

    public function clientId(): string { return (string) $this->config['client_id']; }

    /**
     * Claims of a compact RS256 JWS whose iss is one of $issuers (default: the configured issuer) and whose
     * signature checks out against that issuer's JWKS. Nothing else is checked.
     *
     * @param list<string>|null $issuers
     */
    public function verifySignature(string $jwt, ?array $issuers = null): array
    {
        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) throw new RuntimeException('The sign-in token is malformed.');
        $header = json_decode(self::b64uDecode($parts[0]), true);
        $claims = json_decode(self::b64uDecode($parts[1]), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('The sign-in token is malformed.');
        }

        $iss = (string) ($claims['iss'] ?? '');
        if (! in_array($iss, $issuers ?? [$this->issuer()], true)) throw new RuntimeException('Wrong issuer.');

        $key = $this->jwk((string) ($header['kid'] ?? ''), $iss);
        $ok  = $key && openssl_verify("{$parts[0]}.{$parts[1]}", self::b64uDecode($parts[2]), $key, OPENSSL_ALGO_SHA256) === 1;
        if (! $ok) throw new RuntimeException('The sign-in token signature is not valid.');
        return $claims;
    }

    /**
     * A provisioning event (staff SSO part 2): signature, iss, aud = our client id, exp (at most 5 min
     * ahead of iat) and a jti. Replay is checked by the caller, which owns the seen-jti store.
     */
    public function verifyProvisionToken(string $jwt): array
    {
        $c   = $this->verifySignature($jwt, $this->acceptedIssuers());
        $now = time();
        if (! in_array($this->clientId(), (array) ($c['aud'] ?? []), true)) throw new RuntimeException('Wrong audience.');
        $exp = (int) ($c['exp'] ?? 0);
        if ($exp < $now - 30) throw new RuntimeException('Expired.');
        if ($exp > $now + 600 || (int) ($c['iat'] ?? 0) > $now + 60) throw new RuntimeException('Not valid yet.');
        if (trim((string) ($c['jti'] ?? '')) === '' || strlen((string) $c['jti']) > 191) throw new RuntimeException('Missing jti.');
        if (! in_array($c['event'] ?? '', ['upsert', 'disable'], true)) throw new RuntimeException('Unknown event.');
        if (! str_starts_with((string) ($c['sub'] ?? ''), 'staff:')) throw new RuntimeException('Not a staff subject.');
        return $c;
    }

    /** $issuer: the issuer this sign-in started under (default: the configured one). The id_token's iss must equal it. */
    public function verifyIdToken(string $jwt, string $nonce, ?string $issuer = null): array
    {
        $issuer ??= $this->issuer();
        try {
            $claims = $this->verifySignature($jwt, [$issuer]);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Wrong issuer.') throw new RuntimeException('The sign-in token has the wrong issuer.');
            throw $e;
        }
        $now = time();
        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($this->config['client_id'], $aud, true)) throw new RuntimeException('The sign-in token is not for AI Studio.');
        if (count($aud) > 1 && ($claims['azp'] ?? '') !== $this->config['client_id']) throw new RuntimeException('The sign-in token is not for AI Studio.');
        if ((int) ($claims['exp'] ?? 0) < $now - 60) throw new RuntimeException('The sign-in token has expired.');
        if ((int) ($claims['iat'] ?? 0) > $now + 300) throw new RuntimeException('The sign-in token is not valid yet.');
        if (! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) throw new RuntimeException('The sign-in token does not match this sign-in.');
        return $claims;
    }

    /**
     * A back-channel logout token (OIDC Back-Channel Logout 1.0): RS256 against the JWKS of its iss, iss
     * accepted, aud has our client id, iat within 5 minutes, exp (when present) not passed, the logout
     * event, no nonce, and sub or sid. Replay (jti) is checked by the caller, which owns the seen-jti store.
     */
    public function verifyLogoutToken(string $jwt): array
    {
        $c   = $this->verifySignature($jwt, $this->acceptedIssuers());
        $now = time();
        if (! in_array($this->clientId(), (array) ($c['aud'] ?? []), true)) throw new RuntimeException('Wrong audience.');
        if (! isset($c['iat']) || abs($now - (int) $c['iat']) > 300) throw new RuntimeException('Stale or future iat.');
        if (isset($c['exp']) && (int) $c['exp'] < $now) throw new RuntimeException('Expired.');
        if (! is_array($c['events'] ?? null) || ! array_key_exists(self::LOGOUT_EVENT, $c['events'])) throw new RuntimeException('Not a logout token.');
        if (array_key_exists('nonce', $c)) throw new RuntimeException('A logout token must not carry a nonce.');
        if (trim((string) ($c['sub'] ?? '')) === '' && trim((string) ($c['sid'] ?? '')) === '') throw new RuntimeException('No sub or sid.');
        if (trim((string) ($c['jti'] ?? '')) === '' || strlen((string) $c['jti']) > 180) throw new RuntimeException('Missing jti.');
        return $c;
    }

    /**
     * Discovery document of $issuer (default: the configured one), cached for an hour per issuer.
     * Tries {issuer}/.well-known then {issuer}/oauth/.well-known; the document must name that issuer.
     */
    public function discovery(?string $issuer = null): array
    {
        $issuer = rtrim($issuer ?? $this->issuer(), '/');
        $ck = 'wsc_oidc_disc_' . md5($issuer);
        if (is_array($d = cache($ck))) return $d;
        foreach (['/.well-known/openid-configuration', '/oauth/.well-known/openid-configuration'] as $path) {
            [$status, $body] = ($this->http)('GET', $issuer . $path);
            $d = json_decode((string) $body, true);
            if ($status === 200 && is_array($d) && ($d['issuer'] ?? '') === $issuer
                && ! empty($d['authorization_endpoint']) && ! empty($d['token_endpoint']) && ! empty($d['jwks_uri'])) {
                cache()->save($ck, $d, 3600);
                return $d;
            }
        }
        throw new RuntimeException('WorkSuite Cloud sign-in is unavailable right now.');
    }

    /** Public key for $kid from $issuer's JWKS (cached 10 min per issuer; refetched once when the kid is unknown, for rotation). */
    private function jwk(string $kid, string $issuer)
    {
        $ck = 'wsc_oidc_jwks_' . md5($issuer);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $keys = $attempt === 0 ? cache($ck) : null;
            if (! is_array($keys)) {
                [$status, $body] = ($this->http)('GET', $this->discovery($issuer)['jwks_uri']);
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
