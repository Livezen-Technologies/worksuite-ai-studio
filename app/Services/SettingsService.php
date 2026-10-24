<?php

namespace App\Services;

/**
 * SettingsService — grouped key/value config persisted in the `settings` table,
 * with sensible defaults per group and secret masking on read.
 */
class SettingsService
{
    protected $db;

    /** group => [key => default] */
    public const DEFAULTS = [
        'general' => [
            'site_name' => 'OllamaHub', 'company_name' => '', 'timezone' => 'UTC', 'language' => 'en',
        ],
        'ai' => [
            'default_model' => '', 'streaming' => '1', 'timeout' => '300',
            'retry_count' => '1', 'cache' => '0', 'compression' => '0',
        ],
        'smtp' => [
            'host' => '', 'port' => '587', 'username' => '', 'password' => '',
            'encryption' => 'tls', 'from_email' => '', 'from_name' => 'OllamaHub',
        ],
        'storage' => [
            'driver' => 'local', 's3_bucket' => '', 's3_region' => '',
            's3_key' => '', 's3_secret' => '', 's3_endpoint' => '',
        ],
        'notifications' => [
            'email_enabled' => '0', 'slack_webhook' => '', 'discord_webhook' => '',
            'telegram_bot_token' => '', 'telegram_chat_id' => '', 'webhook_url' => '',
        ],
        'gateway' => [
            // Reverse-proxy / load-balancing behaviour for the /api/v1 gateway.
            'strategy' => 'round_robin',   // round_robin | random | least_busy
            'failover' => '1',             // try the next healthy node on error
            'sticky_sessions' => '0',      // pin a key to one node where possible
            'max_retries' => '2',          // failover attempts per request
            'health_interval' => '60',     // seconds between scheduled health checks
            'include_degraded' => '1',     // route to degraded (not just online) nodes
        ],
    ];

    /** keys whose values are masked when read back. */
    private const SECRET_KEYS = ['password', 's3_secret', 'telegram_bot_token'];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** All settings for a group merged over defaults (secrets masked). */
    public function group(string $group, bool $mask = true): array
    {
        $defaults = self::DEFAULTS[$group] ?? [];
        $rows = $this->db->table('settings')->where('group', $group)->get()->getResultArray();
        $out  = $defaults;
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        if ($mask) {
            foreach (self::SECRET_KEYS as $k) {
                if (! empty($out[$k])) {
                    $out[$k] = '••••••••';
                }
            }
        }
        return $out;
    }

    /** Raw value (unmasked) for internal use. */
    public function get(string $group, string $key, $default = null)
    {
        $row = $this->db->table('settings')->where('group', $group)->where('key', $key)->get()->getRowArray();
        if ($row !== null) {
            return $row['value'];
        }
        return self::DEFAULTS[$group][$key] ?? $default;
    }

    /** Upsert a group's keys. Masked secret placeholders are ignored (keep existing). */
    public function save(string $group, array $kv): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($kv as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true) && $value === '••••••••') {
                continue; // unchanged masked secret
            }
            $value = is_array($value) ? json_encode($value) : (string) $value;
            $existing = $this->db->table('settings')->where('group', $group)->where('key', $key)->get()->getRowArray();
            if ($existing) {
                $this->db->table('settings')->where('id', $existing['id'])->update(['value' => $value, 'updated_at' => $now]);
            } else {
                $this->db->table('settings')->insert(['group' => $group, 'key' => $key, 'value' => $value, 'updated_at' => $now]);
            }
        }
    }
}
