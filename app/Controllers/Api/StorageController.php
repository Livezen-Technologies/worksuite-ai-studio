<?php

namespace App\Controllers\Api;

use App\Services\StorageService;

/**
 * Storage nodes (part 1).
 *   GET    /api/storage                         summary, nodes, open alerts, alert rules           (storage.view)
 *   GET    /api/storage/nodes/{id}              node, 7-day check history, allocations, alerts      (storage.view)
 *   POST   /api/storage/nodes                   add (starts in testing and is checked at once)      (storage.manage)
 *   PUT    /api/storage/nodes/{id}              edit (credentials only when given)                  (storage.manage)
 *   DELETE /api/storage/nodes/{id}              remove a testing or retired node                    (storage.manage)
 *   POST   /api/storage/nodes/{id}/test         run a health check now                              (storage.manage)
 *   POST   /api/storage/nodes/{id}/status       lifecycle: activate, drain, retire, re-test         (storage.manage)
 *   POST   /api/storage/nodes/{id}/allocations  assign to an app / user / service / organization    (storage.manage)
 *   DELETE /api/storage/allocations/{id}
 *   POST|PUT|DELETE /api/storage/rules[/{id}]   alert rules                                         (storage.manage)
 *   POST   /api/storage/alerts/{id}/ack         acknowledge an open alert                           (storage.manage)
 */
class StorageController extends BaseApiController
{
    private function db() { return \Config\Database::connect(); }
    private function svc(): StorageService { return new StorageService(); }

    private function node(int $id): ?array
    {
        return $this->db()->table('storage_nodes')->where('id', $id)->where('deleted_at', null)->get()->getRowArray() ?: null;
    }

    public function index()
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $nodes = array_map([StorageService::class, 'public'], $this->db()->table('storage_nodes')->where('deleted_at', null)->orderBy('status = "retired"', '', false)->orderBy('priority')->orderBy('name')->get()->getResultArray());
        $alerts = $this->db()->table('storage_alerts a')->select('a.*, n.name AS node_name')->join('storage_nodes n', 'n.id = a.node_id', 'left')
            ->where('a.resolved_at', null)->orderBy('a.level = "error"', 'DESC', false)->orderBy('a.opened_at', 'DESC')->get()->getResultArray();
        $rules = $this->db()->table('storage_alert_rules')->orderBy('id')->get()->getResultArray();
        $alloc = $this->db()->table('storage_allocations')->select('node_id, COUNT(*) n', false)->groupBy('node_id')->get()->getResultArray();
        $counts = array_column($alloc, 'n', 'node_id');
        foreach ($nodes as &$n) $n['allocations'] = (int) ($counts[$n['id']] ?? 0);
        return $this->ok(['summary' => $this->svc()->summary(), 'nodes' => $nodes, 'alerts' => $alerts, 'rules' => $rules, 'flow' => StorageService::FLOW]);
    }

    public function show($id = null)
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $n = $this->node((int) $id);
        if (! $n) return $this->failure('Storage node not found.', 404);
        $db = $this->db();
        return $this->ok([
            'node'        => StorageService::public($n),
            'checks'      => $db->table('storage_checks')->where('node_id', $n['id'])->where('at >=', date('Y-m-d H:i:s', time() - 7 * 86400))->orderBy('at')->get()->getResultArray(),
            'allocations' => $db->table('storage_allocations')->where('node_id', $n['id'])->orderBy('id')->get()->getResultArray(),
            'alerts'      => $db->table('storage_alerts')->where('node_id', $n['id'])->orderBy('opened_at', 'DESC')->limit(50)->get()->getResultArray(),
        ]);
    }

    /** Validated row from the form. Credentials are sealed; empty secret fields keep the stored ones on edit. */
    private function fields(array $in, ?array $existing): array
    {
        $kind = $existing['kind'] ?? (string) ($in['kind'] ?? '');
        if (! in_array($kind, StorageService::KINDS, true)) throw new \InvalidArgumentException('Choose local, mounted or S3.');
        $name = trim((string) ($in['name'] ?? $existing['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('Give the node a name.');
        $c = is_array($in['config'] ?? null) ? $in['config'] : (json_decode((string) ($existing['config'] ?? ''), true) ?: []);
        $config = $kind === 's3'
            ? ['endpoint' => trim((string) ($c['endpoint'] ?? '')), 'bucket' => trim((string) ($c['bucket'] ?? '')), 'region' => trim((string) ($c['region'] ?? '')),
               'path_style' => ! empty($c['path_style']), 'prefix' => trim((string) ($c['prefix'] ?? 'aistudio'), '/') ?: 'aistudio']
            : ['path' => rtrim(trim((string) ($c['path'] ?? '')), '/')];
        $secret = StorageService::open($existing['secret'] ?? null);
        foreach (['access_key', 'secret_key'] as $k) {
            if (isset($in['secret'][$k]) && trim((string) $in['secret'][$k]) !== '') $secret[$k] = trim((string) $in['secret'][$k]);
        }
        $row = ['name' => mb_substr($name, 0, 120), 'kind' => $kind, 'config' => json_encode($config), 'secret' => $kind === 's3' ? StorageService::seal($secret) : null,
                'region' => mb_substr(trim((string) ($in['region'] ?? $existing['region'] ?? '')), 0, 60) ?: null,
                'tags' => mb_substr(trim((string) ($in['tags'] ?? $existing['tags'] ?? '')), 0, 255) ?: null,
                'priority' => (int) ($in['priority'] ?? $existing['priority'] ?? 100), 'readonly' => ! empty($in['readonly'] ?? $existing['readonly'] ?? 0) ? 1 : 0,
                'quota_bytes' => isset($in['quota_gb']) && $in['quota_gb'] !== '' && $in['quota_gb'] !== null ? (int) round((float) $in['quota_gb'] * 1073741824) : ($existing['quota_bytes'] ?? null)];
        \App\Libraries\Storage\StorageDriver::for(['kind' => $kind], $config, $secret);   // validates the settings
        return $row;
    }

    public function create()
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        try { $row = $this->fields($this->input(), null); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('storage_nodes')->insert($row + ['status' => 'testing', 'health' => 'unknown', 'created_by' => $this->userId(), 'created_at' => $now, 'updated_at' => $now,
            'status_history' => json_encode([['from' => null, 'to' => 'testing', 'at' => $now, 'by' => $this->userId(), 'reason' => 'added']])]);
        $n = $this->node((int) $this->db()->insertID());
        $checked = $this->svc()->check($n);
        return $this->ok($checked, $checked['health'] === 'down' ? 'Node added, but its first check failed: ' . $checked['last_error'] : 'Node added and checked. Activate it when you are ready.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $n = $this->node((int) $id);
        if (! $n) return $this->failure('Storage node not found.', 404);
        try { $row = $this->fields($this->input(), $n); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        $this->db()->table('storage_nodes')->where('id', $n['id'])->update($row + ['updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok($this->svc()->check($this->node($n['id'])), 'Saved and checked.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $n = $this->node((int) $id);
        if (! $n) return $this->failure('Storage node not found.', 404);
        if (! in_array($n['status'], ['testing', 'retired'], true)) return $this->failure('Drain and retire the node before removing it.', 409);
        $this->db()->table('storage_nodes')->where('id', $n['id'])->update(['deleted_at' => date('Y-m-d H:i:s'), 'secret' => null]);
        $this->db()->table('storage_allocations')->where('node_id', $n['id'])->delete();
        $this->db()->table('storage_alerts')->where('node_id', $n['id'])->where('resolved_at', null)->update(['resolved_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Node removed. Files on it were not touched.');
    }

    public function test($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $n = $this->node((int) $id);
        if (! $n) return $this->failure('Storage node not found.', 404);
        $c = $this->svc()->check($n);
        return $this->ok($c, $c['health'] === 'down' ? 'Check failed: ' . $c['last_error'] : 'Check passed.');
    }

    public function status($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $n = $this->node((int) $id);
        if (! $n) return $this->failure('Storage node not found.', 404);
        $in = $this->input();
        try {
            return $this->ok($this->svc()->transition($n, (string) ($in['to'] ?? ''), $this->userId(), isset($in['reason']) ? mb_substr((string) $in['reason'], 0, 255) : null), 'Status changed.');
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->failure($e->getMessage(), 409);
        }
    }

    public function allocate($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $n = $this->node((int) $id);
        if (! $n) return $this->failure('Storage node not found.', 404);
        $in = $this->input();
        $type = (string) ($in['scope_type'] ?? '');
        if (! in_array($type, ['app', 'user', 'service', 'organization'], true)) return $this->failure('Assign to an app, user, service or organization.', 422);
        $sid = trim((string) ($in['scope_id'] ?? ''));
        if ($sid === '') return $this->failure('Say which one.', 422);
        $this->db()->table('storage_allocations')->insert([
            'node_id' => $n['id'], 'scope_type' => $type, 'scope_id' => mb_substr($sid, 0, 120),
            'quota_bytes' => isset($in['quota_gb']) && $in['quota_gb'] !== '' ? (int) round((float) $in['quota_gb'] * 1073741824) : null,
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null, 'created_by' => $this->userId(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Allocated.', 201);
    }

    public function unallocate($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $this->db()->table('storage_allocations')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Allocation removed.');
    }

    private function rule(array $in): array
    {
        $metric = (string) ($in['metric'] ?? '');
        if (! in_array($metric, ['used_pct', 'free_gb', 'latency_ms', 'write_mbps', 'down'], true)) throw new \InvalidArgumentException('Unknown metric.');
        return ['name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: StorageService::metricLabel($metric), 'metric' => $metric,
                'op' => ($in['op'] ?? 'gt') === 'lt' ? 'lt' : 'gt', 'threshold' => (float) ($in['threshold'] ?? 0),
                'level' => ($in['level'] ?? 'warning') === 'error' ? 'error' : 'warning', 'node_id' => (int) ($in['node_id'] ?? 0) ?: null,
                'enabled' => array_key_exists('enabled', $in) ? ($in['enabled'] ? 1 : 0) : 1];
    }

    public function ruleCreate()
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        try { $row = $this->rule($this->input()); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        $this->db()->table('storage_alert_rules')->insert($row + ['created_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Rule added.', 201);
    }

    public function ruleUpdate($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        try { $row = $this->rule($this->input()); } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        $this->db()->table('storage_alert_rules')->where('id', (int) $id)->update($row);
        return $this->ok(null, 'Rule saved.');
    }

    public function ruleDelete($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $this->db()->table('storage_alert_rules')->where('id', (int) $id)->delete();
        $this->db()->table('storage_alerts')->where('rule_id', (int) $id)->where('resolved_at', null)->update(['resolved_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Rule deleted.');
    }

    public function ack($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $this->db()->table('storage_alerts')->where('id', (int) $id)->update(['acknowledged_by' => $this->userId()]);
        return $this->ok(null, 'Acknowledged.');
    }
}
