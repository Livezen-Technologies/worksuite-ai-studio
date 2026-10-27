<?php

namespace App\Controllers\Api;

use App\Services\ObjectStoreService;

/**
 * Storage part 2 — files across nodes, placement policies, background tasks.
 *   GET    /api/storage/files?app=&prefix=&q=      folder listing (folders from key prefixes) + files     (storage.view)
 *   POST   /api/storage/files                      upload (multipart: file, app, key, policy_id)          (storage.manage)
 *   GET    /api/storage/files/{id}[/download]      details with copies / the file itself                  (storage.view)
 *   PUT    /api/storage/files/{id}                 rename or move (key only; disks untouched)             (storage.manage)
 *   DELETE /api/storage/files/{id}                 delete (copies removed in the background)              (storage.manage)
 *   POST   /api/storage/files/{id}/transfer        move a copy: from_node, to_node (optional)             (storage.manage)
 *   GET|POST|PUT|DELETE /api/storage/policies[/{id}]
 *   GET    /api/storage/tasks?status=              the queue; POST tasks/{id}/retry
 */
class StorageFilesController extends BaseApiController
{
    private function db() { return \Config\Database::connect(); }

    public function index()
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $app = (string) ($this->request->getGet('app') ?? '');
        $prefix = trim((string) ($this->request->getGet('prefix') ?? ''), '/');
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $db = $this->db();
        $apps = $db->table('storage_objects')->select('app, COUNT(*) files, SUM(size) bytes', false)->where('deleted_at', null)->groupBy('app')->orderBy('app')->get()->getResultArray();
        if ($app === '' && $apps) $app = $apps[0]['app'];
        $b = $db->table('storage_objects o')->select('o.id, o.app, o.object_key, o.name, o.size, o.mime, o.created_at,
                (SELECT COUNT(*) FROM storage_placements p WHERE p.object_id = o.id AND p.status = "ok") AS copies', false)
            ->where('o.deleted_at', null)->where('o.app', $app);
        if ($q !== '') $b->like('o.object_key', $q);
        elseif ($prefix !== '') $b->like('o.object_key', $prefix . '/', 'after');
        $rows = $b->orderBy('o.object_key')->limit(5000)->get()->getResultArray();
        $folders = []; $files = [];
        foreach ($rows as $r) {
            $rest = $q !== '' ? $r['object_key'] : ($prefix === '' ? $r['object_key'] : substr($r['object_key'], strlen($prefix) + 1));
            if ($q === '' && str_contains($rest, '/')) {
                $f = strstr($rest, '/', true);
                $folders[$f] ??= ['name' => $f, 'files' => 0, 'bytes' => 0];
                $folders[$f]['files']++; $folders[$f]['bytes'] += (int) $r['size'];
            } else $files[] = $r;
        }
        return $this->ok(['apps' => $apps, 'app' => $app, 'prefix' => $prefix, 'folders' => array_values($folders), 'files' => array_slice($files, 0, 1000)]);
    }

    public function upload()
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $f = $this->request->getFile('file');
        if (! $f || ! $f->isValid()) return $this->failure('Choose a file to upload.', 422);
        $app = (string) ($this->request->getPost('app') ?: 'files');
        $folder = trim((string) $this->request->getPost('folder'), '/');
        $key = (string) ($this->request->getPost('key') ?: ($folder !== '' ? $folder . '/' : '') . $f->getClientName());
        try {
            $o = (new ObjectStoreService())->put($app, $key, $f->getTempName(), $f->getClientName(), (int) $this->request->getPost('policy_id') ?: null, $this->userId(), $f->getClientMimeType());
        } catch (\InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422); }
        catch (\RuntimeException $e) { return $this->failure($e->getMessage(), 409); }
        return $this->ok($o, 'Uploaded. Extra copies are made in the background.', 201);
    }

    private function live(int $id): ?array
    {
        $o = $this->db()->table('storage_objects')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        return $o ? (new ObjectStoreService())->object($id) : null;
    }

    public function show($id = null)
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $o = $this->live((int) $id);
        if (! $o) return $this->failure('File not found.', 404);
        $o['tasks'] = $this->db()->table('storage_tasks')->where('object_id', $o['id'])->orderBy('id', 'DESC')->limit(20)->get()->getResultArray();
        $o['policy'] = (new ObjectStoreService())->policy($o['policy_id'] ? (int) $o['policy_id'] : null, $o['app']);
        return $this->ok($o);
    }

    public function download($id = null)
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $o = $this->live((int) $id);
        if (! $o) return $this->failure('File not found.', 404);
        try { $tmp = (new ObjectStoreService())->fetch($o); }
        catch (\RuntimeException $e) { return $this->failure($e->getMessage(), 503); }
        register_shutdown_function(fn () => @unlink($tmp));
        return $this->response->download($tmp, null)->setFileName($o['name'])->setContentType($o['mime'] ?: 'application/octet-stream');
    }

    public function rename($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $o = $this->live((int) $id);
        if (! $o) return $this->failure('File not found.', 404);
        $key = trim(preg_replace('#/+#', '/', str_replace('\\', '/', (string) ($this->input()['key'] ?? ''))), '/');
        if ($key === '' || str_contains($key, '..')) return $this->failure('Give the file a path, e.g. reports/2026/q3.pdf.', 422);
        if ($this->db()->table('storage_objects')->where(['app' => $o['app'], 'object_key' => $key, 'deleted_at' => null])->where('id !=', $o['id'])->countAllResults()) {
            return $this->failure('Another file already has that path.', 409);
        }
        $this->db()->table('storage_objects')->where('id', $o['id'])->update(['object_key' => $key, 'name' => mb_substr(basename($key), 0, 255), 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Moved.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        if (! $this->live((int) $id)) return $this->failure('File not found.', 404);
        (new ObjectStoreService())->remove((int) $id);
        return $this->ok(null, 'Deleted. Its copies are removed in the background.');
    }

    public function transfer($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $o = $this->live((int) $id);
        if (! $o) return $this->failure('File not found.', 404);
        $in = $this->input();
        $from = (int) ($in['from_node'] ?? 0);
        if (! in_array($from, array_map('intval', array_column($o['placements'], 'node_id')), true)) return $this->failure('That node holds no copy of this file.', 422);
        try { (new ObjectStoreService())->transfer($o['id'], $from, (int) ($in['to_node'] ?? 0) ?: null); }
        catch (\RuntimeException $e) { return $this->failure($e->getMessage(), 409); }
        return $this->ok(null, 'Move queued.');
    }

    public function policies()
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $rows = $this->db()->query('SELECT p.*, (SELECT COUNT(*) FROM storage_objects o WHERE o.policy_id = p.id AND o.deleted_at IS NULL) AS files FROM storage_policies p ORDER BY p.id')->getResultArray();
        return $this->ok($rows);
    }

    public function policySave($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $in = $this->input();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return $this->failure('Give the policy a name.', 422);
        $strategy = (string) ($in['strategy'] ?? 'capacity');
        if (! in_array($strategy, ['capacity', 'priority', 'round_robin'], true)) return $this->failure('Unknown strategy.', 422);
        $row = ['name' => mb_substr($name, 0, 100), 'replicas' => max(1, min(5, (int) ($in['replicas'] ?? 2))), 'min_regions' => max(1, min(3, (int) ($in['min_regions'] ?? 1))), 'strategy' => $strategy,
                'node_tags' => mb_substr(trim((string) ($in['node_tags'] ?? '')), 0, 255) ?: null,
                'app' => strtolower(trim((string) ($in['app'] ?? ''))) ?: null, 'is_default' => ! empty($in['is_default']) ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        $db = $this->db();
        if ($row['is_default']) $db->table('storage_policies')->update(['is_default' => 0]);
        if ($id) $db->table('storage_policies')->where('id', (int) $id)->update($row);
        else $db->table('storage_policies')->insert($row + ['created_at' => $row['updated_at']]);
        return $this->ok(null, $id ? 'Policy saved. Files are topped up to the new copy count in the background.' : 'Policy added.', $id ? 200 : 201);
    }

    public function policyDelete($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $p = $this->db()->table('storage_policies')->where('id', (int) $id)->get()->getRowArray();
        if (! $p) return $this->failure('Policy not found.', 404);
        if ($p['is_default']) return $this->failure('Make another policy the default first.', 409);
        $this->db()->table('storage_objects')->where('policy_id', $p['id'])->update(['policy_id' => null]);
        $this->db()->table('storage_policies')->where('id', $p['id'])->delete();
        return $this->ok(null, 'Policy deleted; its files follow the default policy now.');
    }

    public function tasks()
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $status = (string) ($this->request->getGet('status') ?? '');
        $b = $this->db()->table('storage_tasks t')->select('t.*, o.object_key, o.app, f.name AS from_name, d.name AS to_name')
            ->join('storage_objects o', 'o.id = t.object_id', 'left')->join('storage_nodes f', 'f.id = t.from_node', 'left')->join('storage_nodes d', 'd.id = t.to_node', 'left');
        if ($status !== '') $b->where('t.status', $status);
        $counts = array_column($this->db()->table('storage_tasks')->select('status, COUNT(*) n', false)->groupBy('status')->get()->getResultArray(), 'n', 'status');
        return $this->ok(['tasks' => $b->orderBy('t.id', 'DESC')->limit(300)->get()->getResultArray(), 'counts' => $counts]);
    }

    public function retry($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $this->db()->table('storage_tasks')->where('id', (int) $id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Queued again.');
    }

    public function backups()
    {
        if ($r = $this->requirePermission('storage.view')) return $r;
        $db = $this->db();
        $rows = $db->table('storage_backups')->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$b) {
            $b['runs'] = $db->table('storage_backup_runs r')->select('r.*, o.object_key')->join('storage_objects o', 'o.id = r.object_id AND o.deleted_at IS NULL', 'left')
                ->where('r.backup_id', $b['id'])->orderBy('r.id', 'DESC')->limit(20)->get()->getResultArray();
        }
        return $this->ok($rows);
    }

    public function backupSave($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $in = $this->input();
        $row = ['schedule' => ($in['schedule'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily', 'at_hour' => max(0, min(23, (int) ($in['at_hour'] ?? 2))),
                'keep' => max(1, min(365, (int) ($in['keep'] ?? 14))), 'policy_id' => (int) ($in['policy_id'] ?? 0) ?: null,
                'exclude_tables' => mb_substr(preg_replace('/[^\w,]/', '', (string) ($in['exclude_tables'] ?? '')), 0, 2000) ?: null,
                'enabled' => ! empty($in['enabled']) ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        $this->db()->table('storage_backups')->where('id', (int) $id)->update($row);
        return $this->ok(null, 'Backup saved.');
    }

    public function backupRun($id = null)
    {
        if ($r = $this->requirePermission('storage.manage')) return $r;
        $b = $this->db()->table('storage_backups')->where('id', (int) $id)->get()->getRowArray();
        if (! $b) return $this->failure('Backup not found.', 404);
        try { $run = (new \App\Services\StorageBackupService())->start($b, 'manual'); }
        catch (\RuntimeException $e) { return $this->failure($e->getMessage(), 409); }
        return $this->ok(['run_id' => $run], 'Backup started. It runs in the background.');
    }
}
