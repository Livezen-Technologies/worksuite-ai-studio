<?php

namespace App\Services;

/**
 * TotpService — RFC 6238 time-based one-time passwords (SHA-1, 6 digits, 30 s), the format every
 * authenticator app speaks, plus one-time recovery codes. No external deps.
 *
 * Secrets are stored AES-256-GCM encrypted with a key derived from JWT_SECRET; recovery codes are
 * stored as SHA-256 hashes and burned on use.
 */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits  = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /** otpauth:// URI for authenticator apps (what a QR code would encode). */
    public function uri(string $secret, string $account, string $issuer = 'WorkSuite AI Studio'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * The 30 s step $code matches (current or one either side, for clock drift), or null. Steps at or
     * before $lastStep are refused so a code cannot be replayed.
     */
    public function verify(string $secret, string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv(time(), 30);
        for ($step = $now - 1; $step <= $now + 1; $step++) {
            if (($lastStep === null || $step > $lastStep) && hash_equals($this->at($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    private function at(string $secret, int $step): string
    {
        $hash   = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $num    = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v !== false) {
                $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    public function decrypt(?string $stored): ?string
    {
        $raw = $stored ? base64_decode($stored, true) : false;
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return hash('sha256', 'totp|' . env('JWT_SECRET', 'ollamahub-dev-secret-change-me-32-chars-minimum'), true);
    }

    /** Turn TOTP on for a user (after they confirmed a code at $step); returns fresh recovery codes. */
    public function enable(int $userId, string $secret, int $step): array
    {
        \Config\Database::connect()->table('users')->where('id', $userId)->update([
            'totp_secret'     => $this->encrypt($secret),
            'totp_enabled_at' => date('Y-m-d H:i:s'),
            'totp_last_step'  => $step,
        ]);
        return $this->newRecoveryCodes($userId);
    }

    public function disable(int $userId): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->where('id', $userId)->update(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => null]);
        $db->table('user_recovery_codes')->where('user_id', $userId)->delete();
    }

    /**
     * Check a second-factor code for $user (a users row): a TOTP code, or else a recovery code.
     * Records the TOTP step so the same code cannot be used twice.
     */
    public function check(array $user, string $code): bool
    {
        $secret = $this->decrypt($user['totp_secret'] ?? null);
        if ($secret !== null) {
            $last = isset($user['totp_last_step']) ? (int) $user['totp_last_step'] : null;
            $step = $this->verify($secret, $code, $last);
            if ($step !== null) {
                \Config\Database::connect()->table('users')->where('id', $user['id'])->update(['totp_last_step' => $step]);
                return true;
            }
        }
        return $this->useRecoveryCode((int) $user['id'], $code);
    }

    /** Replace a user's recovery codes with 10 fresh ones; returns the plain codes (shown once). */
    public function newRecoveryCodes(int $userId): array
    {
        $db    = \Config\Database::connect();
        $codes = [];
        $rows  = [];
        for ($i = 0; $i < 10; $i++) {
            $c       = strtolower(bin2hex(random_bytes(5)));
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $rows[]  = ['user_id' => $userId, 'code_hash' => hash('sha256', $c), 'created_at' => date('Y-m-d H:i:s')];
        }
        $db->table('user_recovery_codes')->where('user_id', $userId)->delete();
        $db->table('user_recovery_codes')->insertBatch($rows);
        return $codes;
    }

    /** Burn a recovery code. Accepts it with or without the dash, any case. */
    public function useRecoveryCode(int $userId, string $code): bool
    {
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
        if (strlen($norm) !== 10) {
            return false;
        }
        $db  = \Config\Database::connect();
        $row = $db->table('user_recovery_codes')
            ->where('user_id', $userId)->where('code_hash', hash('sha256', $norm))->where('used_at', null)
            ->get()->getRowArray();
        if (! $row) {
            return false;
        }
        $db->table('user_recovery_codes')->where('id', $row['id'])->where('used_at', null)->update(['used_at' => date('Y-m-d H:i:s')]);
        return $db->affectedRows() > 0;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return \Config\Database::connect()->table('user_recovery_codes')
            ->where('user_id', $userId)->where('used_at', null)->countAllResults();
    }
}
