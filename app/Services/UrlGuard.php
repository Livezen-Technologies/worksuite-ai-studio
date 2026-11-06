<?php

namespace App\Services;

/**
 * Server-side request forgery guard for URLs the app will call (node api_url, provider URLs).
 *
 * Loopback and private ranges stay allowed on purpose: Ollama nodes normally live on 127.0.0.1 or
 * the LAN. What is refused is the cloud metadata service and other link-local / unroutable
 * addresses, which expose instance credentials, plus non-http(s) schemes.
 */
class UrlGuard
{
    private const BLOCKED_HOSTS = ['metadata', 'metadata.google.internal', 'metadata.azure.com', 'instance-data', 'instance-data.ec2.internal'];

    /** Null when the URL may be used, otherwise a human-readable reason. */
    public static function reason(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The URL must start with http:// or https:// and include a host.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Put credentials in the API key field, not in the URL.';
        }
        if (in_array(rtrim($host, '.'), self::BLOCKED_HOSTS, true)) {
            return 'That host is a cloud metadata service and cannot be used.';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::resolve($host) ?? []);
        foreach ($ips as $ip) {
            if (self::blockedIp($ip)) {
                return "That address ({$ip}) is link-local or reserved and cannot be used.";
            }
        }
        return null;
    }

    public static function blockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            foreach ([['169.254.0.0', 16], ['0.0.0.0', 8], ['100.100.100.200', 32], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$net, $bits]) {
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip = strtolower($ip);
            if ($ip === '::' || str_starts_with($ip, 'fe8') || str_starts_with($ip, 'fe9') || str_starts_with($ip, 'fea') || str_starts_with($ip, 'feb') || str_starts_with($ip, 'ff') || $ip === 'fd00:ec2::254') {
                return true;
            }
            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) {
                return self::blockedIp($m[1]);
            }
        }
        return false;
    }

    /** @return string[]|null */
    private static function resolve(string $host): ?array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (! empty($r['ipv6'])) $v6[] = $r['ipv6'];
        }
        return array_merge($v4, $v6) ?: null;
    }
}
