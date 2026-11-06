<?php

namespace App\Libraries;

/**
 * Rules for NEW passwords (create user, admin reset, self-service change). Existing hashes are
 * never re-checked, so current users keep signing in with what they have.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** The problem with $password as a user-facing sentence, or null when it is acceptable. */
    public static function problem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        $lower = mb_strtolower($password);
        if ($email !== '' && ($lower === mb_strtolower(trim($email)) || $lower === mb_strtolower(strstr(trim($email), '@', true) ?: ''))) {
            return 'Password must not be your email address.';
        }
        static $common = null;
        $common ??= array_flip(array_map('mb_strtolower', require __DIR__ . '/common_passwords.php'));
        if (isset($common[$lower])) {
            return 'This password is too common. Choose something harder to guess.';
        }
        return null;
    }
}
