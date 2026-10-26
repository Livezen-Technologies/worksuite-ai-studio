<?php

namespace App\Services;

use CodeIgniter\HTTP\IncomingRequest;
use RuntimeException;

/**
 * WebAuthnService — passkey registration and sign-in (WebAuthn Level 2), no external deps.
 *
 * Supports ES256 (-7) and RS256 (-257) credentials with "none" attestation — the attestation statement
 * is not checked, which is what passkey providers (iCloud Keychain, Google Password Manager, Windows
 * Hello, 1Password…) send anyway. User verification is required, so a passkey on its own satisfies 2FA.
 *
 * Challenges travel in short-lived signed step tokens (JwtService), so nothing is stored between the
 * options and verify calls.
 *
 * Relying party: the request's Origin must be in WEBAUTHN_ORIGINS (comma list; default
 * https://<Host> plus the Vite dev server on localhost); the RP ID is that origin's host unless
 * WEBAUTHN_RP_ID is set.
 */
class WebAuthnService
{
    public const RP_NAME = 'WorkSuite AI Studio';

    private string $origin;
    private string $rpId;

    public function __construct(IncomingRequest $request)
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) env('WEBAUTHN_ORIGINS', ''))));
        if (! $allowed) {
            $allowed = ['https://' . $request->getServer('HTTP_HOST'), 'http://localhost:5191'];
        }
        $origin = rtrim($request->getHeaderLine('Origin'), '/');
        if ($origin === '' || ! in_array($origin, $allowed, true)) {
            throw new RuntimeException('Passkeys are not available from this address.');
        }
        $this->origin = $origin;
        $this->rpId   = (string) (env('WEBAUTHN_RP_ID') ?: parse_url($origin, PHP_URL_HOST));
    }

    public function rpId(): string
    {
        return $this->rpId;
    }

    public static function challenge(): string
    {
        return self::b64u(random_bytes(32));
    }

    /**
     * Verify a navigator.credentials.create() response.
     * $cred: { id, rawId, type, response: { clientDataJSON, attestationObject, transports? } } (base64url).
     * Returns [credential_id (b64url), public_key (PEM), alg, sign_count, transports].
     */
    public function verifyRegistration(array $cred, string $challenge): array
    {
        $this->checkClientData((string) ($cred['response']['clientDataJSON'] ?? ''), 'webauthn.create', $challenge);

        $att = self::cbor(self::b64uDecode((string) ($cred['response']['attestationObject'] ?? '')));
        if (! is_array($att) || ! isset($att['authData']) || ! is_string($att['authData'])) {
            throw new RuntimeException('Malformed attestation.');
        }
        $auth = $this->parseAuthData($att['authData']);
        if (! ($auth['flags'] & 0x40) || $auth['credentialId'] === null) {
            throw new RuntimeException('The authenticator did not return a credential.');
        }

        $cose = $auth['coseKey'];
        $alg  = (int) ($cose[3] ?? 0);
        $pem  = match (true) {
            $alg === -7 && ($cose[1] ?? null) === 2 && ($cose[-1] ?? null) === 1 => self::ecPem($cose[-2], $cose[-3]),
            $alg === -257 && ($cose[1] ?? null) === 3                          => self::rsaPem($cose[-1], $cose[-2]),
            default => throw new RuntimeException('This passkey uses an unsupported algorithm.'),
        };

        $transports = array_values(array_filter((array) ($cred['response']['transports'] ?? []), 'is_string'));
        return [self::b64u($auth['credentialId']), $pem, $alg, $auth['signCount'], implode(',', $transports)];
    }

    /**
     * Verify a navigator.credentials.get() response against a stored passkey row.
     * Returns the new sign count.
     */
    public function verifyAssertion(array $cred, string $challenge, array $passkey): int
    {
        $clientData = self::b64uDecode((string) ($cred['response']['clientDataJSON'] ?? ''));
        $this->checkClientData((string) ($cred['response']['clientDataJSON'] ?? ''), 'webauthn.get', $challenge);

        $authData = self::b64uDecode((string) ($cred['response']['authenticatorData'] ?? ''));
        $auth     = $this->parseAuthData($authData);

        $sig = self::b64uDecode((string) ($cred['response']['signature'] ?? ''));
        $ok  = openssl_verify($authData . hash('sha256', $clientData, true), $sig, $passkey['public_key'], OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('Passkey signature is invalid.');
        }

        $stored = (int) $passkey['sign_count'];
        if (($auth['signCount'] !== 0 || $stored !== 0) && $auth['signCount'] <= $stored) {
            throw new RuntimeException('This passkey may have been cloned; sign-in refused.');
        }
        return $auth['signCount'];
    }

    private function checkClientData(string $b64, string $type, string $challenge): void
    {
        $cd = json_decode(self::b64uDecode($b64), true);
        if (! is_array($cd)
            || ($cd['type'] ?? '') !== $type
            || ! hash_equals($challenge, (string) ($cd['challenge'] ?? ''))
            || ($cd['origin'] ?? '') !== $this->origin) {
            throw new RuntimeException('Passkey response did not match this sign-in request.');
        }
    }

    private function parseAuthData(string $d): array
    {
        if (strlen($d) < 37 || ! hash_equals(hash('sha256', $this->rpId, true), substr($d, 0, 32))) {
            throw new RuntimeException('Passkey belongs to a different site.');
        }
        $flags = ord($d[32]);
        if (! ($flags & 0x01) || ! ($flags & 0x04)) {
            throw new RuntimeException('Passkey sign-in needs user verification (PIN, fingerprint or face).');
        }
        $out = ['flags' => $flags, 'signCount' => unpack('N', substr($d, 33, 4))[1], 'credentialId' => null, 'coseKey' => null];
        if ($flags & 0x40) {
            $len                 = unpack('n', substr($d, 53, 2))[1];
            $out['credentialId'] = substr($d, 55, $len);
            $out['coseKey']      = self::cbor(substr($d, 55 + $len));
        }
        return $out;
    }

    // ---- encoding helpers ----

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** Minimal CBOR decoder (ints, byte/text strings, arrays, maps, simple values) — enough for WebAuthn. */
    public static function cbor(string $data)
    {
        $pos = 0;
        return self::cborItem($data, $pos);
    }

    private static function cborItem(string $d, int &$p)
    {
        if ($p >= strlen($d)) {
            throw new RuntimeException('Truncated CBOR.');
        }
        $b     = ord($d[$p++]);
        $major = $b >> 5;
        $info  = $b & 0x1f;
        $val   = match (true) {
            $info < 24  => $info,
            $info === 24 => ord($d[$p++]),
            $info === 25 => unpack('n', substr($d, ($p += 2) - 2, 2))[1],
            $info === 26 => unpack('N', substr($d, ($p += 4) - 4, 4))[1],
            $info === 27 => unpack('J', substr($d, ($p += 8) - 8, 8))[1],
            default      => throw new RuntimeException('Unsupported CBOR encoding.'),
        };
        switch ($major) {
            case 0: return $val;
            case 1: return -1 - $val;
            case 2:
            case 3:
                $s = substr($d, $p, $val);
                $p += $val;
                return $s;
            case 4:
                $a = [];
                for ($i = 0; $i < $val; $i++) {
                    $a[] = self::cborItem($d, $p);
                }
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $val; $i++) {
                    $k     = self::cborItem($d, $p);
                    $m[$k] = self::cborItem($d, $p);
                }
                return $m;
            case 7:
                return match ($info) { 20 => false, 21 => true, 22 => null, default => throw new RuntimeException('Unsupported CBOR value.') };
        }
        throw new RuntimeException('Unsupported CBOR type.');
    }

    private static function ecPem(string $x, string $y): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return self::pem($der);
    }

    private static function rsaPem(string $n, string $e): string
    {
        $int  = static fn (string $v) => self::der(0x02, (ord($v[0]) & 0x80) ? "\x00" . $v : $v);
        $key  = self::der(0x30, $int($n) . $int($e));
        $algo = self::der(0x30, hex2bin('06092a864886f70d0101010500'));
        return self::pem(self::der(0x30, $algo . self::der(0x03, "\x00" . $key)));
    }

    private static function der(int $tag, string $body): string
    {
        $len = strlen($body);
        if ($len < 128) {
            $l = chr($len);
        } else {
            $bytes = ltrim(pack('N', $len), "\x00");
            $l     = chr(0x80 | strlen($bytes)) . $bytes;
        }
        return chr($tag) . $l . $body;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
