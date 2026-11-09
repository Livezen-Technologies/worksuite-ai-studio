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

    /**
     * Vet $url and return a CURLOPT_RESOLVE list ("host:port:ip") pinning the request to the
     * address that was checked, so a second DNS lookup by curl cannot be rebound elsewhere.
     * Throws \InvalidArgumentException when the URL is refused or its host does not resolve.
     *
     * @return string[]
     */
    public static function pin(string $url): array
    {
        if (($why = self::reason($url)) !== null) {
            throw new \InvalidArgumentException($why);
        }
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [];
        }
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80));
        $ips = self::resolve($host);
        if (! $ips) {
            throw new \InvalidArgumentException('That host does not resolve.');
        }
        foreach ($ips as $ip) {
            if (self::blockedIp($ip)) {
                throw new \InvalidArgumentException("That address ({$ip}) is link-local or reserved and cannot be used.");
            }
        }
        $ip = $ips[0];
        return ["{$host}:{$port}:" . (str_contains($ip, ':') ? "[{$ip}]" : $ip)];
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
            // Compare raw bytes, so every spelling of an address (hex or dotted mapped form, zero
            // compression, leading zeros) is judged the same.
            $b = inet_pton($ip);
            if ($b === false || strlen($b) !== 16) return true;
            if ($b === str_repeat("\0", 16)) return true;                                     // ::
            if ($b === str_repeat("\0", 15) . "\x01") return false;                           // ::1 loopback: local nodes
            $first = ord($b[0]); $second = ord($b[1]);
            if ($first === 0xfe && ($second & 0xc0) === 0x80) return true;                      // fe80::/10 link-local
            if ($first === 0xfe && ($second & 0xc0) === 0xc0) return true;                      // fec0::/10 site-local (deprecated)
            if ($first === 0xff) return true;                                                   // multicast
            if ($b === inet_pton('fd00:ec2::254')) return true;                                 // AWS metadata over IPv6
            // IPv4 carried inside IPv6: mapped ::ffff:0:0/96, compatible ::/96, NAT64 64:ff9b::/96.
            // The embedded IPv4 gets the IPv4 rules (so a mapped 169.254.169.254 is refused).
            $prefix = substr($b, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12) || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
                return self::blockedIp(inet_ntop(substr($b, 12)));
            }
        }
        return false;
    }

    /**
     * curl options that re-vet $url now and pin the connection to the checked address. Used on
     * every call to a node or provider, so a hostname re-pointed at an internal address after it
     * was saved (DNS rebinding) is refused. A host that does not resolve is left for curl to fail.
     *
     * @throws \InvalidArgumentException when the address is refused
     */
    public static function curlOpts(string $url): array
    {
        try {
            $resolve = self::pin($url);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'That host does not resolve.') return [];
            throw $e;
        }
        return [CURLOPT_RESOLVE => $resolve, CURLOPT_FOLLOWLOCATION => false];
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
