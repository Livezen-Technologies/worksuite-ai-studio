<?php

declare(strict_types=1);

namespace App\Libraries\Gateway;

use RuntimeException;

/**
 * WorkSuite API Gateway upstream identity assertion, receiver side (API Management data-plane contract §8).
 *
 * Behind the gateway (api.worksuitecloud.com) a request arrives without the caller's key or token; the gateway sends
 * `Authorization: WS-API-Gateway <jwt>` instead, a 30-second ES256 token saying who the caller is and which request it
 * is bound to. This is a port of the reference verifier (api_maanger `gateway/internal/assertion/verify.go`, and the
 * TypeScript port in worksuite-iot `platform/src/gateway-assertion.ts`): the same rules in the same order with the same
 * rejection codes, checked against the cross-language test vectors. It has no framework dependency: the app supplies
 * the key source, the replay store and the clock.
 */
final class GatewayAssertion
{
    public const SCHEME = 'WS-API-Gateway';
    public const TYPE = 'wsapi-gateway+jwt';
    public const ALGORITHM = 'ES256';
    public const DEFAULT_ISSUER = 'https://api.worksuitecloud.com';
    public const DEFAULT_JWKS_URL = 'https://api.worksuitecloud.com/.well-known/apim-gateway-jwks.json';
    public const LEEWAY_S = 30;
    public const MAX_LIFETIME_S = 60;
    public const REPLAY_WINDOW_S = 120;
    public const JWKS_CACHE_S = 300;
    public const JWKS_MIN_REFETCH_S = 30;

    private const STRING_CLAIMS = ['iss', 'aud', 'jti', 'htm', 'htp', 'sub', 'sub_type', 'org', 'project', 'env', 'cred',
        'cred_type', 'client_id', 'api', 'route', 'region', 'node'];

    /** @var callable(string): mixed  kid → OpenSSL public key; throws GatewayAssertionError('unknown_kid') */
    private $keys;
    /** @var callable(string, int): bool  replay id, keep-until (unix s) → true when the id was new */
    private $replay;
    /** @var callable(): float  unix seconds */
    private $now;

    /**
     * @param callable(string): mixed $keys
     * @param callable(string, int): bool $replay
     * @param callable(): float|null $now
     */
    public function __construct(
        callable $keys,
        private readonly string $issuer,
        private readonly string $audience,
        callable $replay,
        ?callable $now = null,
        private readonly int $leeway = self::LEEWAY_S,
        private readonly int $maxLifetime = self::MAX_LIFETIME_S,
    ) {
        if ($issuer === '' || $audience === '') {
            throw new RuntimeException('gateway assertion verifier needs keys, issuer and audience');
        }
        $this->keys = $keys;
        $this->replay = $replay;
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /**
     * Verifies a token for a request with this method and path (without query), in the reference order:
     *  1. three base64url parts; header {"alg":"ES256","typ":"wsapi-gateway+jwt","kid":…}, no "crit";
     *  2. kid names a key of the gateway JWKS and the 64-byte r||s signature verifies under it;
     *  3. iss is the configured issuer, aud is exactly this receiver's audience (a single string);
     *  4. 0 < exp - iat ≤ 60 s, exp not passed and iat not in the future, each with 30 s leeway;
     *  5. htm and htp equal the request's method and path;
     *  6. jti is present and not seen before (recorded only when every other check passed).
     *
     * @return array<string, mixed> the claims, with absent strings as '' and absent numbers as 0
     *
     * @throws GatewayAssertionError
     */
    public function verify(string $token, string $method, string $path): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw self::malformed('want 3 parts, got ' . count($parts));
        }
        $hb = self::b64url($parts[0]);
        if ($hb === null) {
            throw self::malformed('header encoding');
        }
        $h = self::jsonObject($hb, 'header');
        $alg = self::str($h, 'alg', 'header');
        $typ = self::str($h, 'typ', 'header');
        $kid = self::str($h, 'kid', 'header');
        if ($alg !== self::ALGORITHM) {
            throw new GatewayAssertionError('alg', 'alg is not ES256: ' . json_encode($alg));
        }
        if ($typ !== self::TYPE) {
            throw new GatewayAssertionError('typ', 'typ is not ' . self::TYPE . ': ' . json_encode($typ));
        }
        if (array_key_exists('crit', $h)) {
            throw self::malformed('crit header not supported');
        }
        try {
            $key = ($this->keys)($kid);
        } catch (GatewayAssertionError $e) {
            throw $e->reason === 'unknown_kid' ? $e : new GatewayAssertionError('unknown_kid', 'unknown signing key: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new GatewayAssertionError('unknown_kid', 'unknown signing key: ' . $e->getMessage());
        }
        $sig = self::b64url($parts[2]);
        // A DER-encoded ECDSA signature (what most crypto libraries emit by default) is not a JWS ES256 signature.
        if ($sig === null || strlen($sig) !== 64) {
            throw new GatewayAssertionError('signature', 'bad signature: ES256 signature must be 64 bytes r||s');
        }
        $ok = @openssl_verify($parts[0] . '.' . $parts[1], self::rawToDer($sig), $key, OPENSSL_ALGO_SHA256);
        while (openssl_error_string() !== false) {
            // drain the OpenSSL error queue so it does not leak into later calls
        }
        if ($ok !== 1) {
            throw new GatewayAssertionError('signature', 'bad signature');
        }
        $pb = self::b64url($parts[1]);
        if ($pb === null) {
            throw self::malformed('claims encoding');
        }
        $c = self::decodeClaims(self::jsonObject($pb, 'claims'));
        if ($c['iss'] !== $this->issuer) {
            throw new GatewayAssertionError('iss', 'wrong issuer: ' . json_encode($c['iss']));
        }
        if ($c['aud'] !== $this->audience) {
            throw new GatewayAssertionError('aud', 'wrong audience: ' . json_encode($c['aud']));
        }
        $life = $c['exp'] - $c['iat'];
        if ($c['iat'] <= 0 || $life <= 0 || $life > $this->maxLifetime) {
            throw new GatewayAssertionError('lifetime', "lifetime longer than allowed: exp - iat = {$life} s");
        }
        $now = ($this->now)();
        if ($now > $c['exp'] + $this->leeway) {
            throw new GatewayAssertionError('expired', 'expired');
        }
        if ($c['iat'] > $now + $this->leeway) {
            throw new GatewayAssertionError('iat_future', 'issued in the future');
        }
        if ($c['htm'] !== $method) {
            throw new GatewayAssertionError('htm', 'htm does not match the request method: ' . json_encode($c['htm']) . ' vs ' . json_encode($method));
        }
        if ($c['htp'] !== $path) {
            throw new GatewayAssertionError('htp', 'htp does not match the request path: ' . json_encode($c['htp']) . ' vs ' . json_encode($path));
        }
        if ($c['jti'] === '') {
            throw self::malformed('no jti');
        }
        $keep = max($c['exp'] + $this->leeway, (int) ceil($now) + self::REPLAY_WINDOW_S);
        if (!($this->replay)($c['aud'] . "\0" . $c['jti'], $keep)) {
            throw new GatewayAssertionError('replay', 'jti already used');
        }

        return $c;
    }

    /** The token of `Authorization: WS-API-Gateway <jwt>` (scheme case-sensitive, as in Go), else null. */
    public static function token(?string $authorization): ?string
    {
        if ($authorization === null || $authorization === '') {
            return null;
        }
        $i = strpos($authorization, ' ');
        if ($i === false || substr($authorization, 0, $i) !== self::SCHEME) {
            return null;
        }
        $t = trim(substr($authorization, $i + 1));

        return $t === '' ? null : $t;
    }

    /** True when the Authorization header uses the WS-API-Gateway scheme at all (valid or not). */
    public static function hasScheme(?string $authorization): bool
    {
        return $authorization !== null && explode(' ', $authorization, 2)[0] === self::SCHEME;
    }

    /** The path the gateway signs: percent-decoded, without query (Go `r.URL.Path`). */
    public static function requestPath(string $rawPath): string
    {
        $q = strpos($rawPath, '?');
        if ($q !== false) {
            $rawPath = substr($rawPath, 0, $q);
        }

        return rawurldecode($rawPath);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Reads a JSON Web Key Set (array or JSON text) into kid → PEM, keeping P-256 ES256 signing keys whose point is on
     * the curve (OpenSSL refuses off-curve points). Other keys are skipped; for a repeated kid the first wins.
     *
     * @param array<mixed>|string $jwks
     *
     * @return array<string, string>
     */
    public static function pemsFromJwks(array|string $jwks): array
    {
        $set = is_string($jwks) ? json_decode($jwks, true) : $jwks;
        if (!is_array($set) || !isset($set['keys']) || !is_array($set['keys'])) {
            throw new RuntimeException('gateway JWKS: no keys array');
        }
        $out = [];
        foreach ($set['keys'] as $j) {
            if (!is_array($j)) {
                continue;
            }
            $kid = $j['kid'] ?? null;
            if (($j['kty'] ?? null) !== 'EC' || ($j['crv'] ?? null) !== 'P-256' || !is_string($kid) || $kid === ''
                || !is_string($j['x'] ?? null) || !is_string($j['y'] ?? null)) {
                continue;
            }
            $use = $j['use'] ?? '';
            $alg = $j['alg'] ?? '';
            if (($use !== '' && $use !== 'sig') || ($alg !== '' && $alg !== self::ALGORITHM) || isset($out[$kid])) {
                continue;
            }
            $x = self::b64url($j['x']);
            $y = self::b64url($j['y']);
            if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
                continue;
            }
            // SubjectPublicKeyInfo for id-ecPublicKey / prime256v1 with an uncompressed point.
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $k = @openssl_pkey_get_public($pem);
            while (openssl_error_string() !== false) {
            }
            if ($k === false) {
                continue; // not on the curve
            }
            $out[$kid] = $pem;
        }

        return $out;
    }

    /**
     * A key source over fixed PEMs (kid → PEM).
     *
     * @param array<string, string> $pems
     *
     * @return callable(string): mixed
     */
    public static function staticKeys(array $pems): callable
    {
        return static function (string $kid) use ($pems) {
            if (!isset($pems[$kid])) {
                throw new GatewayAssertionError('unknown_kid', 'unknown signing key ' . json_encode($kid));
            }
            $k = openssl_pkey_get_public($pems[$kid]);
            if ($k === false) {
                throw new GatewayAssertionError('unknown_kid', 'unusable signing key ' . json_encode($kid));
            }

            return $k;
        };
    }

    /**
     * The gateway JWKS fetched over HTTP, with the contract's caching policy: keys are cached for 5 minutes; an unknown
     * kid (key rotation) triggers a refetch at most every 30 s; when the gateway cannot be reached, or answers with
     * something that is not a JWKS, the cached keys keep working.
     *
     * @param callable(): ?array{pems: array<string,string>, fetched_at: int, tried_at: int} $load  read the shared cache
     * @param callable(array{pems: array<string,string>, fetched_at: int, tried_at: int}): void $store  write it
     * @param callable(string): string $fetch  GET the JWKS (throws on a non-200 answer or a network error)
     * @param callable(): float|null $now
     *
     * @return callable(string): mixed
     */
    public static function remoteKeys(string $url, callable $load, callable $store, ?callable $fetch = null, ?callable $now = null): callable
    {
        $fetch ??= static fn (string $u): string => self::httpGet($u);
        $now ??= static fn (): float => microtime(true);

        return static function (string $kid) use ($url, $load, $store, $fetch, $now) {
            $t = (int) floor($now());
            $state = $load() ?? ['pems' => [], 'fetched_at' => 0, 'tried_at' => 0];
            $fresh = $state['fetched_at'] > 0 && $t - $state['fetched_at'] < self::JWKS_CACHE_S;
            if (!($fresh && isset($state['pems'][$kid]))
                && ($state['tried_at'] === 0 || $t - $state['tried_at'] >= self::JWKS_MIN_REFETCH_S || ($state['fetched_at'] > 0 && !$fresh))) {
                $state['tried_at'] = $t;
                try {
                    $text = $fetch($url);
                    if (strlen($text) > 1 << 20) {
                        throw new RuntimeException('gateway JWKS: larger than 1 MiB');
                    }
                    $state['pems'] = self::pemsFromJwks($text);
                    $state['fetched_at'] = $t;
                } catch (\Throwable) {
                    // keep serving the cached keys while the gateway is unreachable
                }
                $store($state);
            }
            if ($state['fetched_at'] === 0) {
                throw new GatewayAssertionError('unknown_kid', 'gateway JWKS unavailable');
            }

            return self::staticKeys($state['pems'])($kid);
        };
    }

    private static function httpGet(string $url): string
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: application/jwk-set+json, application/json\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($body === false || $status !== 200) {
            throw new RuntimeException("gateway JWKS: HTTP {$status}");
        }

        return $body;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Trusted network path
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Parses `…_GATEWAY_TRUSTED_CIDRS`: CIDRs or single addresses, IPv4 or IPv6, separated by commas or spaces.
     * Throws on an entry that is not an address or CIDR, so a typo cannot silently trust nothing (or too much).
     *
     * @param string|list<string> $spec
     *
     * @return list<array{0: string, 1: int}> packed network address and prefix length
     */
    public static function parseCidrs(string|array $spec): array
    {
        $entries = is_array($spec) ? $spec : preg_split('/[\s,]+/', $spec);
        $out = [];
        foreach ($entries as $e) {
            $e = trim((string) $e);
            if ($e === '') {
                continue;
            }
            $p = explode('/', $e);
            $bin = count($p) <= 2 ? @inet_pton(self::unmap($p[0])) : false;
            if ($bin === false) {
                throw new RuntimeException("GATEWAY_TRUSTED_CIDRS: \"{$e}\" is not an IP address or CIDR");
            }
            $max = strlen($bin) * 8;
            $bits = $p[1] ?? (string) $max;
            if (!preg_match('/^\d{1,3}$/', $bits) || (int) $bits > $max) {
                throw new RuntimeException("GATEWAY_TRUSTED_CIDRS: \"{$e}\" is not an IP address or CIDR");
            }
            $out[] = [$bin, (int) $bits];
        }

        return $out;
    }

    /** @param list<array{0: string, 1: int}> $cidrs */
    public static function inCidrs(?string $address, array $cidrs): bool
    {
        if ($address === null || trim($address) === '') {
            return false;
        }
        $a = @inet_pton(self::unmap(trim($address)));
        if ($a === false) {
            return false;
        }
        foreach ($cidrs as [$net, $bits]) {
            if (strlen($net) !== strlen($a)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($a, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($a[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address the request came from, for the trusted-network check: the socket peer, or — behind the app's own
     * reverse proxy — the address that proxy appended last to X-Forwarded-For (the one that connected to the proxy).
     * Never the first X-Forwarded-For entry: a client can write that one itself.
     */
    public static function peer(string $remoteAddr, ?string $xForwardedFor, bool $behindProxy): string
    {
        if ($behindProxy && $xForwardedFor !== null) {
            $hops = array_values(array_filter(array_map('trim', explode(',', $xForwardedFor)), static fn ($x) => $x !== ''));
            if ($hops !== []) {
                return $hops[count($hops) - 1];
            }
        }

        return $remoteAddr;
    }

    private static function unmap(string $a): string
    {
        return preg_match('/^::ffff:\d+\.\d+\.\d+\.\d+$/i', $a) ? substr($a, 7) : $a;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Decoding helpers
    // ------------------------------------------------------------------------------------------------------------

    /** Unpadded base64url, strictly (Go `base64.RawURLEncoding`): null for padding, other characters or an impossible length. */
    private static function b64url(string $s): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $s) || strlen($s) % 4 === 1) {
            return null;
        }
        $d = base64_decode(strtr($s, '-_', '+/'), true);

        return $d === false ? null : $d;
    }

    private static function malformed(string $msg): GatewayAssertionError
    {
        return new GatewayAssertionError('malformed', "malformed token: {$msg}");
    }

    /** @return array<string, mixed> one JSON object (JSON null decodes as an empty one, as in Go) */
    private static function jsonObject(string $b, string $what): array
    {
        try {
            $v = json_decode($b, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw self::malformed("{$what}: " . $e->getMessage());
        }
        if ($v === null) {
            return [];
        }
        if (!$v instanceof \stdClass) {
            throw self::malformed("{$what} is not a JSON object");
        }

        return get_object_vars($v);
    }

    /** @param array<string, mixed> $o */
    private static function str(array $o, string $k, string $what): string
    {
        $v = $o[$k] ?? null;
        if ($v === null) {
            return '';
        }
        if (!is_string($v)) {
            throw self::malformed("{$what}: {$k} is not a string");
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private static function decodeClaims(array $o): array
    {
        $c = [];
        foreach (self::STRING_CLAIMS as $k) {
            $c[$k] = self::str($o, $k, 'claims'); // an array `aud` (plain JWT allows it) is malformed here
        }
        foreach (['iat', 'exp'] as $k) {
            $v = $o[$k] ?? null;
            if ($v === null) {
                $c[$k] = 0;
            } elseif (is_int($v)) {
                $c[$k] = $v;
            } else {
                throw self::malformed("claims: {$k} is not an integer");
            }
        }
        $s = $o['scopes'] ?? null;
        if ($s === null) {
            $c['scopes'] = [];
        } elseif (is_array($s) && array_is_list($s) && array_reduce($s, static fn ($ok, $x) => $ok && is_string($x), true)) {
            $c['scopes'] = $s;
        } else {
            throw self::malformed('claims: scopes is not a list of strings');
        }

        return $c;
    }

    /** JWS raw r||s (64 bytes) → the DER ECDSA-Sig-Value OpenSSL verifies. */
    private static function rawToDer(string $sig): string
    {
        $int = static function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '' || ord($b[0]) > 0x7F) {
                $b = "\0" . $b;
            }

            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($sig, 0, 32)) . $int(substr($sig, 32, 32));

        return "\x30" . chr(strlen($seq)) . $seq;
    }
}
