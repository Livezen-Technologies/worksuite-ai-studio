<?php

namespace App\Services;

use App\Libraries\Storage\StorageDriver;

/**
 * Storage nodes (part 1): settings with encrypted credentials, health checks with history, alert rules, lifecycle.
 * The scheduler checks every node that is not retired every 5 minutes (`storage_health`).
 */
class StorageService
{
    public const KINDS = ['local', 'mounted', 's3'];
    /** lifecycle: which moves are allowed from each status */
    public const FLOW = ['testing' => ['active', 'retired'], 'active' => ['draining'], 'draining' => ['active', 'retired'], 'retired' => ['testing']];

    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    private static function enc(): \CodeIgniter\Encryption\EncrypterInterface
    {
        return \Config\Services::encrypter();
    }

    public static function seal(array $secret): ?string
    {
        $secret = array_filter($secret, fn ($v) => $v !== null && $v !== '');
        return $secret ? base64_encode(self::enc()->encrypt(json_encode($secret))) : null;
    }

    public static function open(?string $sealed): array
    {
        if (! $sealed) return [];
        try { return json_decode(self::enc()->decrypt(base64_decode($sealed)), true) ?: []; }
        catch (\Throwable) { return []; }
    }

    /** A node row as the UI sees it: config, never the secret (only whether one is set). */
    public static function public(array $n): array
    {
        $n['config'] = json_decode((string) $n['config'], true) ?: [];
        $n['has_secret'] = ! empty($n['secret']);
        $n['status_history'] = json_decode((string) ($n['status_history'] ?? ''), true) ?: [];
        $cap = $n['quota_bytes'] ?: $n['total_bytes'];
        $n['capacity_bytes'] = $cap ? (int) $cap : null;
        $n['used_pct'] = $cap && $n['used_bytes'] !== null ? round($n['used_bytes'] / $cap * 100, 1) : null;
        unset($n['secret']);
        return $n;
    }

    public function driver(array $node): StorageDriver
    {
        return StorageDriver::for($node, json_decode((string) $node['config'], true) ?: [], self::open($node['secret']));
    }

    /** Probe a node, store the result and its history, evaluate the alert rules. */
    public function check(array $node): array
    {
        $now = date('Y-m-d H:i:s');
        try {
            $p = $this->driver($node)->probe();
            $health = ($p['latency_ms'] > 2000 || $p['write_mbps'] < 5) ? 'degraded' : 'ok';
            $upd = ['health' => $health, 'total_bytes' => $p['total'], 'used_bytes' => $p['used'], 'free_bytes' => $p['free'],
                    'write_mbps' => $p['write_mbps'], 'read_mbps' => $p['read_mbps'], 'latency_ms' => $p['latency_ms'], 'last_error' => null];
            $error = null;
        } catch (\Throwable $e) {
            $health = 'down'; $error = mb_substr($e->getMessage(), 0, 500); $p = [];
            $upd = ['health' => 'down', 'last_error' => $error];
        }
        $upd['last_check_at'] = $now;
        $this->db->table('storage_nodes')->where('id', $node['id'])->update($upd);
        $this->db->table('storage_checks')->insert([
            'node_id' => $node['id'], 'at' => $now, 'health' => $health, 'total_bytes' => $p['total'] ?? null, 'used_bytes' => $p['used'] ?? null,
            'write_mbps' => $p['write_mbps'] ?? null, 'read_mbps' => $p['read_mbps'] ?? null, 'latency_ms' => $p['latency_ms'] ?? null, 'error' => $error,
        ]);
        $fresh = $this->db->table('storage_nodes')->where('id', $node['id'])->get()->getRowArray();
        $this->evaluate($fresh);
        return self::public($fresh);
    }

    /** Every node that is not retired; old check history (> 30 days) is pruned. */
    public function checkAll(): array
    {
        $out = [];
        foreach ($this->db->table('storage_nodes')->where('deleted_at', null)->where('status !=', 'retired')->get()->getResultArray() as $n) {
            $out[$n['name']] = $this->check($n)['health'];
        }
        $this->db->table('storage_checks')->where('at <', date('Y-m-d H:i:s', time() - 30 * 86400))->delete();
        return $out;
    }

    /** Open an alert when a rule fires for a node, resolve it when it no longer does. Notifies on open. */
    private function evaluate(array $n): void
    {
        $pub = self::public($n);
        $values = [
            'down'       => $n['health'] === 'down' ? 1.0 : 0.0,
            'used_pct'   => $pub['used_pct'],
            'free_gb'    => $n['free_bytes'] !== null ? $n['free_bytes'] / 1073741824 : null,
            'latency_ms' => $n['health'] === 'down' ? null : ($n['latency_ms'] !== null ? (float) $n['latency_ms'] : null),
            'write_mbps' => $n['health'] === 'down' ? null : ($n['write_mbps'] !== null ? (float) $n['write_mbps'] : null),
        ];
        $rules = $this->db->table('storage_alert_rules')->where('enabled', 1)->groupStart()->where('node_id', null)->orWhere('node_id', $n['id'])->groupEnd()->get()->getResultArray();
        foreach ($rules as $r) {
            $v = $values[$r['metric']] ?? null;
            $fires = $v !== null && ($r['op'] === 'gt' ? $v > (float) $r['threshold'] : $v < (float) $r['threshold']);
            $open = $this->db->table('storage_alerts')->where(['rule_id' => $r['id'], 'node_id' => $n['id'], 'resolved_at' => null])->get()->getRowArray();
            if ($fires && ! $open) {
                $msg = $r['metric'] === 'down' ? "{$n['name']} is unreachable: " . ($n['last_error'] ?? 'no answer')
                    : sprintf('%s: %s (%s %s %s)', $n['name'], $r['name'], self::metricLabel($r['metric']), $r['op'] === 'gt' ? 'above' : 'below', rtrim(rtrim(number_format((float) $r['threshold'], 1), '0'), '.'));
                $this->db->table('storage_alerts')->insert(['rule_id' => $r['id'], 'node_id' => $n['id'], 'level' => $r['level'], 'message' => mb_substr($msg, 0, 500), 'value' => $v, 'opened_at' => date('Y-m-d H:i:s')]);
                $ns = new NotificationService();
                $ns->notify(null, 'Storage alert: ' . $r['name'], $msg, $r['level'] === 'error' ? 'error' : 'warning', 'storage');
                if ($r['level'] === 'error') { try { $ns->dispatch('Storage alert: ' . $r['name'], $msg); } catch (\Throwable) {} }
            } elseif (! $fires && $open) {
                $this->db->table('storage_alerts')->where('id', $open['id'])->update(['resolved_at' => date('Y-m-d H:i:s')]);
            }
        }
    }

    public static function metricLabel(string $m): string
    {
        return ['used_pct' => 'used %', 'free_gb' => 'free GB', 'latency_ms' => 'latency ms', 'write_mbps' => 'write MB/s', 'down' => 'unreachable'][$m] ?? $m;
    }

    /** Move a node through its lifecycle, keeping a history. */
    public function transition(array $n, string $to, int $userId, ?string $reason = null): array
    {
        $allowed = self::FLOW[$n['status']] ?? [];
        if (! in_array($to, $allowed, true)) throw new \InvalidArgumentException("A {$n['status']} node cannot become {$to}.");
        if ($to === 'active' && $n['health'] === 'down') throw new \RuntimeException('The node failed its last health check; fix it and run Test before activating.');
        $store = new ObjectStoreService();
        if ($to === 'retired' && ($held = $store->heldBy((int) $n['id']))) throw new \RuntimeException("The node still holds {$held} file(s). Drain it and wait until it is empty before retiring.");
        $hist = json_decode((string) ($n['status_history'] ?? ''), true) ?: [];
        $hist[] = ['from' => $n['status'], 'to' => $to, 'at' => date('Y-m-d H:i:s'), 'by' => $userId, 'reason' => $reason];
        $this->db->table('storage_nodes')->where('id', $n['id'])->update(['status' => $to, 'status_history' => json_encode($hist), 'updated_at' => date('Y-m-d H:i:s')]);
        if ($to === 'draining') $store->drain((int) $n['id']);              // queue every file it holds to move elsewhere
        if ($n['status'] === 'draining' && $to === 'active') {               // drain cancelled
            $this->db->table('storage_tasks')->where(['type' => 'migrate', 'from_node' => $n['id'], 'status' => 'pending'])->delete();
        }
        return self::public($this->db->table('storage_nodes')->where('id', $n['id'])->get()->getRowArray());
    }

    public function summary(): array
    {
        $nodes = $this->db->table('storage_nodes')->where('deleted_at', null)->where('status !=', 'retired')->get()->getResultArray();
        $cap = $used = 0;
        foreach ($nodes as $n) { $c = $n['quota_bytes'] ?: $n['total_bytes']; if ($c) { $cap += (int) $c; $used += (int) $n['used_bytes']; } }
        return [
            'nodes' => count($nodes), 'active' => count(array_filter($nodes, fn ($n) => $n['status'] === 'active')),
            'healthy' => count(array_filter($nodes, fn ($n) => $n['health'] === 'ok')), 'down' => count(array_filter($nodes, fn ($n) => $n['health'] === 'down')),
            'capacity_bytes' => $cap, 'used_bytes' => $used, 'free_bytes' => max(0, $cap - $used),
            'open_alerts' => $this->db->table('storage_alerts')->where('resolved_at', null)->countAllResults(),
        ];
    }
}
