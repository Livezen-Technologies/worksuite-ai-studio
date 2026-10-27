<?php

namespace App\Services;

/**
 * Files across storage nodes (storage part 2).
 *
 * A file is an object (key, app, size, sha256, policy) with placements on one or more nodes. On a node it lives at
 * objects/<uu>/<uuid> — never under its name — so renames never touch disks and any node can hold any file.
 *
 *   put()      writes the first copy now (falling over to the next eligible node if one fails) and queues the rest
 *   fetch()    reads from the healthiest copy, verifies the checksum, falls over to the next copy, queues a repair
 *   drain()    queues every file on a draining node to move elsewhere; sync() runs the queue and tops up replicas
 *
 * Eligible node for new data: active, health ok or degraded, not read-only, open to the app (a node with app
 * allocations takes only those apps), with room under its quota/disk (10 % margin), matching the policy's tags.
 */
class ObjectStoreService
{
    private $db;
    private StorageService $nodes;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->nodes = new StorageService();
    }

    public static function nodeKey(string $uuid): string
    {
        return 'objects/' . substr($uuid, 0, 2) . '/' . $uuid;
    }

    public function policy(?int $id, string $app): array
    {
        $q = $this->db->table('storage_policies');
        $p = $id ? $q->where('id', $id)->get()->getRowArray() : null;
        $p ??= $this->db->table('storage_policies')->where('app', $app)->get()->getRowArray();
        $p ??= $this->db->table('storage_policies')->where('is_default', 1)->get()->getRowArray();
        return $p ?: ['id' => null, 'replicas' => 1, 'strategy' => 'capacity', 'node_tags' => null];
    }

    /** Nodes that may take $size more bytes for $app under $policy, best first. */
    public function eligible(string $app, int $size, array $policy, array $exclude = []): array
    {
        $nodes = $this->db->table('storage_nodes')->where('deleted_at', null)->where('status', 'active')->whereIn('health', ['ok', 'degraded'])
            ->where('readonly', 0)->get()->getResultArray();
        $alloc = [];
        foreach ($this->db->table('storage_allocations')->where('scope_type', 'app')->get()->getResultArray() as $a) $alloc[$a['node_id']][] = strtolower($a['scope_id']);
        $tags = array_filter(array_map('trim', explode(',', strtolower((string) ($policy['node_tags'] ?? '')))));
        $out = [];
        foreach ($nodes as $n) {
            if (in_array((int) $n['id'], $exclude, true)) continue;
            if (isset($alloc[$n['id']]) && ! in_array(strtolower($app), $alloc[$n['id']], true)) continue;   // reserved for other apps
            $ntags = array_map('trim', explode(',', strtolower((string) $n['tags'])));
            if ($tags && array_diff($tags, $ntags)) continue;
            $cap = $n['quota_bytes'] ?: $n['total_bytes'];
            $used = (int) $n['used_bytes'];
            $free = $cap ? $cap - $used : null;
            if ($n['free_bytes'] !== null) $free = $free === null ? (int) $n['free_bytes'] : min($free, (int) $n['free_bytes']);
            if ($free !== null && $free < $size * 1.1 + 64 * 1048576) continue;
            $n['_free'] = $free ?? PHP_INT_MAX; $n['_used_pct'] = $cap ? $used / $cap : 0;
            $out[] = $n;
        }
        usort($out, match ($policy['strategy'] ?? 'capacity') {
            'priority'    => fn ($a, $b) => [$a['priority'], $a['_used_pct']] <=> [$b['priority'], $b['_used_pct']],
            'round_robin' => fn ($a, $b) => $this->placed($a['id']) <=> $this->placed($b['id']),
            default       => fn ($a, $b) => [$a['_used_pct'], $a['priority']] <=> [$b['_used_pct'], $b['priority']],
        });
        return $out;
    }

    private function placed(int $nodeId): int
    {
        return $this->db->table('storage_placements')->where('node_id', $nodeId)->where('status', 'ok')->countAllResults();
    }

    /** Store a local file. Returns the object row. */
    public function put(string $app, string $key, string $localFile, ?string $name = null, ?int $policyId = null, ?int $ownerId = null, ?string $mime = null): array
    {
        $app = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $app)) ?: 'files';
        $key = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $key)), '/');
        if ($key === '' || str_contains($key, '..')) throw new \InvalidArgumentException('Give the file a path, e.g. reports/2026/q3.pdf.');
        $size = filesize($localFile); $sha = hash_file('sha256', $localFile);
        $policy = $this->policy($policyId, $app);
        $nodes = $this->eligible($app, $size, $policy);
        if (! $nodes) throw new \RuntimeException('No active storage node has room for this file. Activate a node, free space, or check the node allocations.');
        $uuid = $this->uuid();
        $first = null; $errors = [];
        foreach ($nodes as $n) {                                           // failover on write: next node if one fails
            try { $this->nodes->driver($n)->put(self::nodeKey($uuid), $localFile); $first = $n; break; }
            catch (\Throwable $e) { $errors[] = "{$n['name']}: " . $e->getMessage(); }
        }
        if (! $first) throw new \RuntimeException('Every eligible node refused the file — ' . implode('; ', $errors));
        $now = date('Y-m-d H:i:s');
        // a new version replaces the file at the same key
        $old = $this->db->table('storage_objects')->where(['app' => $app, 'object_key' => $key, 'deleted_at' => null])->get()->getRowArray();
        $this->db->table('storage_objects')->insert([
            'uuid' => $uuid, 'app' => $app, 'object_key' => $key, 'name' => mb_substr($name ?: basename($key), 0, 255), 'size' => $size, 'sha256' => $sha,
            'mime' => $mime, 'policy_id' => $policy['id'] ?? null, 'owner_id' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        $this->db->table('storage_placements')->insert(['object_id' => $id, 'node_id' => $first['id'], 'status' => 'ok', 'verified_at' => $now, 'created_at' => $now]);
        $this->bumpUsed((int) $first['id'], $size);
        $want = max(1, (int) $policy['replicas']);
        foreach (array_slice(array_values(array_filter($nodes, fn ($n) => $n['id'] !== $first['id'])), 0, $want - 1) as $n) {
            $this->task('replicate', $id, (int) $first['id'], (int) $n['id'], 'new file');
        }
        if ($old) $this->remove((int) $old['id'], 'replaced by a new version');
        return $this->object($id);
    }

    /** Read an object into a local temp file (verified). Falls over between copies. */
    public function fetch(array $obj): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'obj');
        $places = $this->db->table('storage_placements p')->select('p.*, n.health, n.priority')->join('storage_nodes n', 'n.id = p.node_id')
            ->where('p.object_id', $obj['id'])->where('p.status', 'ok')->where('n.deleted_at', null)
            ->orderBy("FIELD(n.health, 'ok', 'degraded', 'unknown', 'down')", '', false)->orderBy('n.priority')->get()->getResultArray();
        $errors = [];
        foreach ($places as $p) {
            $node = $this->db->table('storage_nodes')->where('id', $p['node_id'])->get()->getRowArray();
            try {
                $this->nodes->driver($node)->get(self::nodeKey($obj['uuid']), $tmp);
                if (hash_file('sha256', $tmp) !== $obj['sha256']) throw new \RuntimeException('checksum mismatch — the copy is damaged');
                return $tmp;
            } catch (\Throwable $e) {
                $errors[] = "{$node['name']}: " . $e->getMessage();
                if (str_contains($e->getMessage(), 'checksum') || $node['health'] !== 'down') {   // a bad copy on a working node: repair it
                    $this->db->table('storage_placements')->where('id', $p['id'])->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
                }
            }
        }
        @unlink($tmp);
        throw new \RuntimeException('No copy of this file could be read — ' . ($errors ? implode('; ', $errors) : 'it has no copies'));
    }

    /** Delete: hide now, remove the copies in the background. */
    public function remove(int $objectId, string $reason = 'deleted'): void
    {
        $this->db->table('storage_objects')->where('id', $objectId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        foreach ($this->db->table('storage_placements')->where('object_id', $objectId)->get()->getResultArray() as $p) {
            $this->task('delete', $objectId, (int) $p['node_id'], null, $reason);
        }
    }

    /** Move one object's copy from a node to another (or the best eligible one). */
    public function transfer(int $objectId, int $from, ?int $to = null, string $reason = 'moved by an administrator'): void
    {
        $obj = $this->object($objectId);
        if (! $to) {
            $have = array_map('intval', array_column($this->db->table('storage_placements')->select('node_id')->where('object_id', $objectId)->get()->getResultArray(), 'node_id'));
            $best = $this->eligible($obj['app'], (int) $obj['size'], $this->policy($obj['policy_id'] ? (int) $obj['policy_id'] : null, $obj['app']), $have)[0] ?? null;
            if (! $best) throw new \RuntimeException('No other node can take this file.');
            $to = (int) $best['id'];
        }
        $this->task('migrate', $objectId, $from, $to, $reason);
    }

    /** A node entered draining: move every copy it holds. */
    public function drain(int $nodeId): int
    {
        $n = 0;
        foreach ($this->db->table('storage_placements p')->select('p.object_id')->join('storage_objects o', 'o.id = p.object_id')
                     ->where('p.node_id', $nodeId)->where('o.deleted_at', null)->get()->getResultArray() as $p) {
            if (! $this->db->table('storage_tasks')->where(['object_id' => $p['object_id'], 'from_node' => $nodeId, 'type' => 'migrate'])->whereIn('status', ['pending', 'running'])->countAllResults()) {
                $this->task('migrate', (int) $p['object_id'], $nodeId, null, 'node draining'); $n++;
            }
        }
        return $n;
    }

    public function heldBy(int $nodeId): int
    {
        return $this->db->table('storage_placements p')->join('storage_objects o', 'o.id = p.object_id')->where('p.node_id', $nodeId)->where('o.deleted_at', null)->countAllResults();
    }

    private function task(string $type, int $objectId, ?int $from, ?int $to, string $reason): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('storage_tasks')->insert(['type' => $type, 'object_id' => $objectId, 'from_node' => $from, 'to_node' => $to, 'status' => 'pending', 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Background work, every minute (`storage_sync`): top up under-replicated files (a node down or a bad copy), then
     * run queued tasks for up to ~50 s. A task that fails is retried up to 5 times.
     */
    public function sync(int $budgetSeconds = 50): array
    {
        $t0 = time(); $done = $failed = $queued = 0;
        // 1. replicas: objects with fewer good copies (on live nodes) than their policy wants
        $rows = $this->db->query("SELECT o.id, o.app, o.size, o.policy_id,
                    SUM(p.status = 'ok' AND n.health <> 'down' AND n.status <> 'retired' AND n.deleted_at IS NULL) AS good, COUNT(p.id) AS copies
                FROM storage_objects o LEFT JOIN storage_placements p ON p.object_id = o.id LEFT JOIN storage_nodes n ON n.id = p.node_id
                WHERE o.deleted_at IS NULL GROUP BY o.id, o.app, o.size, o.policy_id LIMIT 2000")->getResultArray();
        foreach ($rows as $o) {
            $want = max(1, (int) $this->policy($o['policy_id'] ? (int) $o['policy_id'] : null, $o['app'])['replicas']);
            if ((int) $o['good'] >= $want || (int) $o['good'] === 0) continue;
            if ($this->db->table('storage_tasks')->where('object_id', $o['id'])->whereIn('type', ['replicate', 'repair'])->whereIn('status', ['pending', 'running'])->countAllResults()) continue;
            $src = $this->db->table('storage_placements p')->select('p.node_id')->join('storage_nodes n', 'n.id = p.node_id')
                ->where(['p.object_id' => $o['id'], 'p.status' => 'ok'])->where('n.health !=', 'down')->get()->getRowArray();
            $this->task('replicate', (int) $o['id'], (int) $src['node_id'], null, 'below its replica count'); $queued++;
        }
        // 2. the queue
        while (time() - $t0 < $budgetSeconds) {
            $t = $this->db->table('storage_tasks')->where('status', 'pending')->orderBy('id')->get(1)->getRowArray();
            if (! $t) break;
            $this->db->table('storage_tasks')->where(['id' => $t['id'], 'status' => 'pending'])->update(['status' => 'running', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->affectedRows() !== 1) continue;
            try { $this->run($t); $this->db->table('storage_tasks')->where('id', $t['id'])->update(['status' => 'done', 'error' => null, 'updated_at' => date('Y-m-d H:i:s')]); $done++; }
            catch (\Throwable $e) {
                $att = (int) $t['attempts'] + 1;
                $this->db->table('storage_tasks')->where('id', $t['id'])->update(['status' => $att >= 5 ? 'failed' : 'pending', 'attempts' => $att, 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => date('Y-m-d H:i:s')]);
                $failed++;
                if ($att >= 5) (new NotificationService())->notify(null, 'Storage task failed', "Task #{$t['id']} ({$t['type']}): " . $e->getMessage(), 'error', 'storage');
                if ($att < 5) break;   // let a struggling node recover before retrying
            }
        }
        $this->finishDrains();
        $this->db->table('storage_tasks')->where('status', 'done')->where('updated_at <', date('Y-m-d H:i:s', time() - 14 * 86400))->delete();
        return ['queued' => $queued, 'done' => $done, 'failed' => $failed];
    }

    private function run(array $t): void
    {
        $obj = $this->db->table('storage_objects')->where('id', $t['object_id'])->get()->getRowArray();
        if (! $obj) return;
        $node = fn ($id) => $this->db->table('storage_nodes')->where('id', $id)->get()->getRowArray();
        if ($t['type'] === 'delete') {
            $n = $node($t['from_node']);
            if ($n && $n['deleted_at'] === null) $this->nodes->driver($n)->delete(self::nodeKey($obj['uuid']));
            $this->db->table('storage_placements')->where(['object_id' => $obj['id'], 'node_id' => $t['from_node']])->delete();
            if ($n) $this->bumpUsed((int) $n['id'], -(int) $obj['size']);
            return;
        }
        if ($obj['deleted_at']) return;                                     // deleted meanwhile
        $have = array_map('intval', array_column($this->db->table('storage_placements')->select('node_id')->where('object_id', $obj['id'])->where('status', 'ok')->get()->getResultArray(), 'node_id'));
        $to = $t['to_node'] ? (int) $t['to_node'] : null;
        if (! $to || in_array($to, $have, true)) {
            $policy = $this->policy($obj['policy_id'] ? (int) $obj['policy_id'] : null, $obj['app']);
            $exclude = array_merge($have, $t['type'] === 'migrate' ? [(int) $t['from_node']] : []);
            $to = (int) (($this->eligible($obj['app'], (int) $obj['size'], $policy, $exclude)[0]['id']) ?? 0);
            if (! $to) throw new \RuntimeException('no other eligible node has room');
        }
        $tmp = $this->fetch($obj);                                          // verified copy from the healthiest node
        try { $this->nodes->driver($node($to))->put(self::nodeKey($obj['uuid']), $tmp); }
        finally { @unlink($tmp); }
        $now = date('Y-m-d H:i:s');
        $this->db->query('INSERT INTO storage_placements (object_id, node_id, status, verified_at, created_at) VALUES (?, ?, "ok", ?, ?)
                          ON DUPLICATE KEY UPDATE status = "ok", error = NULL, verified_at = VALUES(verified_at)', [$obj['id'], $to, $now, $now]);
        $this->bumpUsed($to, (int) $obj['size']);
        // a damaged copy that has been replaced is cleaned up; a migrate removes the source copy
        foreach ($this->db->table('storage_placements')->where('object_id', $obj['id'])->where('status', 'failed')->get()->getResultArray() as $bad) {
            $this->task('delete', (int) $obj['id'], (int) $bad['node_id'], null, 'damaged copy replaced');
        }
        if ($t['type'] === 'migrate' && $t['from_node'] && (int) $t['from_node'] !== $to) {
            $this->task('delete', (int) $obj['id'], (int) $t['from_node'], null, 'moved to another node');
        }
    }

    /** A draining node that holds nothing any more is ready to retire: say so once. */
    private function finishDrains(): void
    {
        foreach ($this->db->table('storage_nodes')->where('status', 'draining')->where('deleted_at', null)->get()->getResultArray() as $n) {
            if ($this->heldBy((int) $n['id']) === 0 && ! $this->db->table('notifications')->where('type', 'storage')->like('title', "drained: {$n['name']}")->where('created_at >=', $n['updated_at'])->countAllResults()) {
                (new NotificationService())->notify(null, "Storage node drained: {$n['name']}", 'It holds no files any more and can be retired.', 'success', 'storage');
            }
        }
    }

    /** Keep a node's used figure roughly current between health checks (S3 has no disk to measure). */
    private function bumpUsed(int $nodeId, int $delta): void
    {
        $this->db->query('UPDATE storage_nodes SET used_bytes = GREATEST(0, COALESCE(used_bytes, 0) + ?) WHERE id = ?', [$delta, $nodeId]);
    }

    public function object(int $id): array
    {
        $o = $this->db->table('storage_objects')->where('id', $id)->get()->getRowArray();
        if (! $o) throw new \RuntimeException('File not found.');
        $o['placements'] = $this->db->table('storage_placements p')->select('p.node_id, p.status, p.verified_at, p.error, n.name AS node_name, n.health, n.status AS node_status')
            ->join('storage_nodes n', 'n.id = p.node_id', 'left')->where('p.object_id', $id)->get()->getResultArray();
        return $o;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
