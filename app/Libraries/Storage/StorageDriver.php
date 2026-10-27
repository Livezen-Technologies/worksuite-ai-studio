<?php

namespace App\Libraries\Storage;

/**
 * One storage node's backend. probe() is the health check: it measures capacity, writes and reads a test object and
 * times both, and throws with a readable reason when the node cannot be used. put/get/delete/listing serve the
 * object store (part 2). Keys are relative ("aistudio/…"); a driver never reaches outside its root or bucket.
 */
abstract class StorageDriver
{
    public const PROBE_BYTES = 4 * 1024 * 1024;
    /** VPS / node storage: the helper's tunnel lands on the node's tunnel port + this (see AgentDriver) */
    public const AGENT_PORT_OFFSET = 2000;

    /** @return array{total:?int, used:?int, free:?int, write_mbps:float, read_mbps:float, latency_ms:float} */
    abstract public function probe(): array;

    abstract public function put(string $key, string $localFile): void;

    abstract public function get(string $key, string $localFile): void;

    abstract public function delete(string $key): void;

    public static function for(array $node, array $config, array $secret): self
    {
        return match ($node['kind']) {
            'local', 'mounted' => new LocalDriver($config, $node['kind'] === 'mounted'),
            's3'               => new S3Driver($config, $secret),
            'agent'            => new AgentDriver($config, $secret),
            default            => throw new \InvalidArgumentException("No driver for {$node['kind']} nodes yet."),
        };
    }

    protected static function key(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if ($key === '' || preg_match('#(^|/)\.\.(/|$)#', $key)) throw new \InvalidArgumentException('Invalid object key.');
        return $key;
    }

    protected static function mbps(int $bytes, float $seconds): float
    {
        return round($bytes / 1048576 / max($seconds, 0.0001), 1);
    }
}

/** A directory on this server: a local disk, or a network mount (NFS / SMB) when $mounted. */
class LocalDriver extends StorageDriver
{
    private string $root;

    public function __construct(array $config, private bool $mounted = false)
    {
        $this->root = rtrim((string) ($config['path'] ?? ''), '/');
        if ($this->root === '' || $this->root[0] !== '/') throw new \InvalidArgumentException('Give an absolute folder path, e.g. /srv/storage.');
        if (in_array($this->root, ['/', '/etc', '/var', '/usr', '/bin', '/boot', '/root', '/home', '/proc', '/sys', '/dev'], true)) {
            throw new \InvalidArgumentException("Choose a dedicated folder, not {$this->root}.");
        }
    }

    public function probe(): array
    {
        if (! is_dir($this->root)) throw new \RuntimeException("{$this->root} does not exist on this server.");
        if ($this->mounted && ! $this->isMountPoint($this->root)) {
            // A dropped NFS/SMB mount leaves an empty local folder — writing there would silently fill the local disk.
            throw new \RuntimeException("{$this->root} is not a mount point right now (the network share is not mounted).");
        }
        if (! is_writable($this->root)) throw new \RuntimeException("{$this->root} is not writable by the web server (www-data).");
        $t0 = microtime(true);
        $total = @disk_total_space($this->root); $free = @disk_free_space($this->root);
        $latency = (microtime(true) - $t0) * 1000;
        $file = $this->root . '/.aistudio-probe-' . bin2hex(random_bytes(4));
        $data = random_bytes(self::PROBE_BYTES);
        $t = microtime(true);
        if (file_put_contents($file, $data) !== strlen($data)) throw new \RuntimeException('Writing a test file failed.');
        $fh = fopen($file, 'rb'); fflush($fh); fclose($fh);
        $w = microtime(true) - $t;
        $t = microtime(true);
        $back = file_get_contents($file);
        $r = microtime(true) - $t;
        @unlink($file);
        if ($back !== $data) throw new \RuntimeException('The test file read back differently from what was written.');
        return ['total' => $total === false ? null : (int) $total, 'used' => $total === false ? null : (int) ($total - $free),
                'free' => $free === false ? null : (int) $free, 'write_mbps' => self::mbps(self::PROBE_BYTES, $w),
                'read_mbps' => self::mbps(self::PROBE_BYTES, $r), 'latency_ms' => round($latency, 1)];
    }

    private function isMountPoint(string $path): bool
    {
        $real = realpath($path);
        foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $mp = str_replace('\\040', ' ', explode(' ', $line)[1] ?? '');
            if ($mp === $real) return true;
        }
        return false;
    }

    private function path(string $key): string
    {
        return $this->root . '/' . self::key($key);
    }

    public function put(string $key, string $localFile): void
    {
        $dst = $this->path($key);
        if (! is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
        if (! copy($localFile, $dst . '.part') || ! rename($dst . '.part', $dst)) throw new \RuntimeException("Could not store {$key}.");
    }

    public function get(string $key, string $localFile): void
    {
        if (! copy($this->path($key), $localFile)) throw new \RuntimeException("Could not read {$key}.");
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }
}

/** An S3-compatible bucket (AWS S3, Garage, MinIO, Wasabi, Cloudflare R2, Backblaze B2 …), signed with AWS SigV4. */
class S3Driver extends StorageDriver
{
    private string $endpoint; private string $bucket; private string $region; private bool $pathStyle; private string $prefix;

    public function __construct(array $config, private array $secret)
    {
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->bucket = (string) ($config['bucket'] ?? '');
        $this->region = (string) ($config['region'] ?? '') ?: 'us-east-1';
        $this->pathStyle = ! array_key_exists('path_style', $config) || (bool) $config['path_style'];
        $this->prefix = trim((string) ($config['prefix'] ?? 'aistudio'), '/');
        if (! preg_match('#^https?://#', $this->endpoint)) throw new \InvalidArgumentException('Give the S3 endpoint URL, e.g. https://s3.eu-central-1.amazonaws.com.');
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $this->bucket)) throw new \InvalidArgumentException('Give a valid bucket name.');
        if (empty($secret['access_key']) || empty($secret['secret_key'])) throw new \InvalidArgumentException('Give the access key and secret key.');
    }

    private function url(string $key = ''): array
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST) . (($p = parse_url($this->endpoint, PHP_URL_PORT)) ? ":{$p}" : '');
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);
        $path = $key === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        if ($this->pathStyle) return ["{$scheme}://{$host}/{$this->bucket}{$path}", $host, "/{$this->bucket}{$path}"];
        return ["{$scheme}://{$this->bucket}.{$host}{$path}", "{$this->bucket}.{$host}", $path === '' ? '/' : $path];
    }

    /** One signed request. $body is a string or a file path ($isFile). Returns [status, body, headers]. */
    private function request(string $method, string $key, array $query = [], string $body = '', bool $isFile = false, ?string $saveTo = null): array
    {
        [$url, $host, $path] = $this->url($key);
        ksort($query);
        $qs = implode('&', array_map(fn ($k, $v) => rawurlencode($k) . '=' . rawurlencode((string) $v), array_keys($query), $query));
        $hash = $isFile ? hash_file('sha256', $body) : hash('sha256', $body);
        $amz = gmdate('Ymd\THis\Z'); $day = substr($amz, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $amz];
        $canonHeaders = implode('', array_map(fn ($k, $v) => "{$k}:{$v}\n", array_keys($headers), $headers));
        $signed = implode(';', array_keys($headers));
        $canon = "{$method}\n{$path}\n{$qs}\n{$canonHeaders}\n{$signed}\n{$hash}";
        $scope = "{$day}/{$this->region}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$amz}\n{$scope}\n" . hash('sha256', $canon);
        $k = hash_hmac('sha256', $day, 'AWS4' . $this->secret['secret_key'], true);
        foreach ([$this->region, 's3', 'aws4_request'] as $part) $k = hash_hmac('sha256', $part, $k, true);
        $sig = hash_hmac('sha256', $toSign, $k);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->secret['access_key']}/{$scope}, SignedHeaders={$signed}, Signature={$sig}";
        $ch = curl_init($url . ($qs !== '' ? "?{$qs}" : ''));
        $h = ["Authorization: {$auth}", "x-amz-content-sha256: {$hash}", "x-amz-date: {$amz}", 'Expect:'];
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_RETURNTRANSFER => $saveTo === null];
        $fh = null; $out = null;
        if ($isFile) { $fh = fopen($body, 'rb'); $opts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($body)]; }
        elseif ($body !== '') $opts[CURLOPT_POSTFIELDS] = $body;
        if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
        if ($saveTo !== null) { $out = fopen($saveTo, 'wb'); $opts[CURLOPT_FILE] = $out; }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        curl_close($ch); if ($fh) fclose($fh); if ($out) fclose($out);
        if ($res === false) throw new \RuntimeException("Cannot reach {$this->endpoint}: {$err}");
        return [$code, $saveTo === null ? (string) $res : ''];
    }

    private function fail(int $code, string $body, string $what): never
    {
        $msg = preg_match('#<Message>(.*?)</Message>#s', $body, $m) ? $m[1] : '';
        $code403 = $code === 403 ? ' — check the access key, secret key and region' : '';
        throw new \RuntimeException("{$what} failed (HTTP {$code}{$code403})" . ($msg ? ": {$msg}" : ''));
    }

    public function probe(): array
    {
        $t0 = microtime(true);
        [$code, $body] = $this->request('GET', '', ['list-type' => '2', 'max-keys' => '1', 'prefix' => $this->prefix . '/']);
        $latency = (microtime(true) - $t0) * 1000;
        if ($code === 404) throw new \RuntimeException("Bucket {$this->bucket} does not exist.");
        if ($code !== 200) $this->fail($code, $body, 'Listing the bucket');
        $key = "{$this->prefix}/.probe-" . bin2hex(random_bytes(4));
        $data = random_bytes(self::PROBE_BYTES);
        $t = microtime(true);
        [$code, $body] = $this->request('PUT', $key, [], $data);
        $w = microtime(true) - $t;
        if ($code !== 200) $this->fail($code, $body, 'Writing a test object');
        $t = microtime(true);
        [$code, $back] = $this->request('GET', $key);
        $r = microtime(true) - $t;
        $this->request('DELETE', $key);
        if ($code !== 200 || $back !== $data) throw new \RuntimeException('The test object read back differently from what was written.');
        $used = $this->usedBytes();
        return ['total' => null, 'used' => $used, 'free' => null, 'write_mbps' => self::mbps(self::PROBE_BYTES, $w),
                'read_mbps' => self::mbps(self::PROBE_BYTES, $r), 'latency_ms' => round($latency, 1)];
    }

    /** Bytes stored under the AI Studio prefix (up to 50 pages of 1,000 objects; S3 has no capacity of its own). */
    private function usedBytes(): ?int
    {
        $total = 0; $token = null;
        for ($page = 0; $page < 50; $page++) {
            $q = ['list-type' => '2', 'prefix' => $this->prefix . '/'] + ($token ? ['continuation-token' => $token] : []);
            [$code, $body] = $this->request('GET', '', $q);
            if ($code !== 200) return null;
            preg_match_all('#<Size>(\d+)</Size>#', $body, $m);
            $total += array_sum(array_map('intval', $m[1]));
            if (! preg_match('#<NextContinuationToken>(.*?)</NextContinuationToken>#', $body, $t)) return $total;
            $token = html_entity_decode($t[1]);
        }
        return $total;
    }

    public function put(string $key, string $localFile): void
    {
        [$code, $body] = $this->request('PUT', $this->prefix . '/' . self::key($key), [], $localFile, true);
        if ($code !== 200) $this->fail($code, $body, "Storing {$key}");
    }

    public function get(string $key, string $localFile): void
    {
        [$code] = $this->request('GET', $this->prefix . '/' . self::key($key), [], '', false, $localFile);
        if ($code !== 200) { @unlink($localFile); throw new \RuntimeException("Reading {$key} failed (HTTP {$code})."); }
    }

    public function delete(string $key): void
    {
        $this->request('DELETE', $this->prefix . '/' . self::key($key));
    }
}

/**
 * A folder on an enrolled node (a VPS or any machine running the AI Studio agent). The agent runs a small storage
 * helper on the node (127.0.0.1:18098, public/node/aistudio-store-node.sh) and forwards it over the node's tunnel to
 * 127.0.0.1:<tunnel port + 2000> here; every request carries the node's storage key (sealed in the node's secret).
 */
class AgentDriver extends StorageDriver
{
    private string $base;
    private string $key;

    public function __construct(array $config, array $secret)
    {
        $sid = (int) ($config['server_id'] ?? 0);
        if (! $sid) throw new \InvalidArgumentException('Choose the node (server) that holds this storage.');
        $srv = \Config\Database::connect()->table('servers')->select('tunnel_port')->where('id', $sid)->get()->getRowArray();
        if (! $srv) throw new \InvalidArgumentException('That server no longer exists.');
        if (! $srv['tunnel_port']) throw new \InvalidArgumentException('That server is not enrolled with a tunnel; enrol it with the node installer first.');
        $this->base = 'http://127.0.0.1:' . ((int) $srv['tunnel_port'] + self::AGENT_PORT_OFFSET);
        $this->key = (string) ($secret['store_key'] ?? '');
    }

    private function call(string $method, string $path, ?string $upload = null, ?string $download = null, int $timeout = 3600): array
    {
        if ($this->key === '') throw new \RuntimeException('The node has no storage key yet; save the node again.');
        $ch = curl_init($this->base . $path);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['X-Store-Key: ' . $this->key, 'Expect:'], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout];
        $in = $out = null;
        if ($upload !== null) { $in = fopen($upload, 'rb'); $opts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => filesize($upload)]; }
        if ($download !== null) { $out = fopen($download, 'wb'); $opts[CURLOPT_FILE] = $out; } else $opts[CURLOPT_RETURNTRANSFER] = true;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
        curl_close($ch); if ($in) fclose($in); if ($out) fclose($out);
        if ($body === false || $code === 0) throw new \RuntimeException('The node\'s storage helper is not reachable through its tunnel' . ($err ? " ({$err})" : '') . '. Is the agent running on the node?');
        if ($code >= 300) throw new \RuntimeException("The node answered {$code}: " . mb_substr(is_string($body) ? $body : '', 0, 200));
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }

    public function probe(): array
    {
        $t0 = microtime(true);
        $st = json_decode($this->call('GET', '/stat', null, null, 15)['body'], true) ?: [];
        $latency = (microtime(true) - $t0) * 1000;
        $src = tempnam(sys_get_temp_dir(), 'pr'); $back = tempnam(sys_get_temp_dir(), 'pb');
        file_put_contents($src, random_bytes(self::PROBE_BYTES));
        try {
            $key = '.aistudio-probe-' . bin2hex(random_bytes(4));
            $t = microtime(true); $this->put($key, $src); $w = microtime(true) - $t;
            $t = microtime(true); $this->get($key, $back); $r = microtime(true) - $t;
            $this->delete($key);
            if (hash_file('sha256', $src) !== hash_file('sha256', $back)) throw new \RuntimeException('The test file read back differently from what was written.');
        } finally { @unlink($src); @unlink($back); }
        return ['total' => isset($st['total']) ? (int) $st['total'] : null, 'used' => isset($st['used']) ? (int) $st['used'] : null, 'free' => isset($st['free']) ? (int) $st['free'] : null,
                'write_mbps' => self::mbps(self::PROBE_BYTES, $w), 'read_mbps' => self::mbps(self::PROBE_BYTES, $r), 'latency_ms' => round($latency, 1)];
    }

    public function put(string $key, string $localFile): void
    {
        $this->call('PUT', '/o/' . self::key($key) . '?sha256=' . hash_file('sha256', $localFile), $localFile);
    }

    public function get(string $key, string $localFile): void
    {
        $this->call('GET', '/o/' . self::key($key), null, $localFile);
    }

    public function delete(string $key): void
    {
        $this->call('DELETE', '/o/' . self::key($key), null, null, 60);
    }
}
