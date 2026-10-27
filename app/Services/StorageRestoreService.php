<?php

namespace App\Services;

/**
 * One-click database restore from a stored backup (storage part 3).
 *
 * Restoring replaces the live database — including the tables that record backups, files and this restore — so:
 *   - progress lives in a file (writable/restore/status.json), never in the database being replaced;
 *   - one restore at a time, never while a backup runs;
 *   - by default a safety backup of the current database is taken first, and the restore stops if it fails;
 *   - after the import, the rows of the safety backup and of the backup just restored (file, copies, run) are written
 *     back when the restored data does not have them, so both stay listed — the safety one can undo the restore;
 *   - the chosen backup is fetched with the object store's checksum check before anything is touched.
 * Tables a backup left out are not in its dump and keep their current contents. Files stored after the backup was
 * taken stay on the nodes but are no longer listed (their rows were not in the backup).
 */
class StorageRestoreService
{
    public const CONFIRM = 'RESTORE';
    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    private static function file(): string
    {
        return WRITEPATH . 'restore/status.json';
    }

    public static function status(): ?array
    {
        $s = json_decode((string) @file_get_contents(self::file()), true);
        if (! $s) return null;
        if (($s['state'] ?? '') === 'running' && ! empty($s['pid']) && ! file_exists('/proc/' . (int) $s['pid'])) {
            $s['state'] = 'failed'; $s['error'] = 'The restore process ended without finishing.'; self::save($s);
        }
        return $s;
    }

    private static function save(array $s): void
    {
        if (! is_dir(dirname(self::file()))) mkdir(dirname(self::file()), 0750, true);
        $s['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents(self::file() . '.tmp', json_encode($s, JSON_UNESCAPED_UNICODE));
        rename(self::file() . '.tmp', self::file());
    }

    private function step(array &$s, string $msg): void
    {
        $s['step'] = $msg; $s['log'][] = date('H:i:s') . ' ' . $msg; self::save($s);
    }

    /** Validate and launch the detached restore. */
    public function start(int $runId, bool $safety, string $confirm, int $userId, string $userName): array
    {
        if (trim($confirm) !== self::CONFIRM) throw new \InvalidArgumentException('Type ' . self::CONFIRM . ' to confirm.');
        $cur = self::status();
        if ($cur && $cur['state'] === 'running') throw new \RuntimeException('A restore is already running.');
        if ($this->db->table('storage_backup_runs')->where('status', 'running')->countAllResults()) throw new \RuntimeException('A backup is running; wait for it to finish.');
        $run = $this->db->table('storage_backup_runs r')->select('r.*, b.name AS backup_name, o.object_key, o.created_at AS taken_at')
            ->join('storage_backups b', 'b.id = r.backup_id')->join('storage_objects o', 'o.id = r.object_id AND o.deleted_at IS NULL')
            ->where(['r.id' => $runId, 'r.status' => 'ok'])->get()->getRowArray();
        if (! $run) throw new \RuntimeException('That backup is not available any more.');
        $s = ['state' => 'running', 'run_id' => $runId, 'object_id' => (int) $run['object_id'], 'backup' => $run['backup_name'], 'taken_at' => $run['taken_at'],
              'object_key' => $run['object_key'], 'safety' => $safety, 'by' => $userName, 'by_id' => $userId, 'started_at' => date('Y-m-d H:i:s'), 'log' => [], 'step' => 'starting'];
        self::save($s);
        $pid = (int) trim((string) shell_exec(sprintf('cd %s && setsid nohup php8.3 spark storage:restore > %s 2>&1 < /dev/null & echo $!',
            escapeshellarg(ROOTPATH), escapeshellarg(WRITEPATH . 'logs/storage-restore-' . date('Ymd-His') . '.log'))));
        $s['pid'] = $pid; self::save($s);
        return $s;
    }

    /** The detached process (`spark storage:restore`). */
    public function run(): void
    {
        $s = self::status();
        if (! $s || $s['state'] !== 'running') return;
        $s['pid'] = getmypid(); self::save($s);
        $store = new ObjectStoreService();
        $dump = null; $cnf = null; $keep = null;
        try {
            // 1. the chosen backup, verified, before anything changes
            $this->step($s, "Fetching {$s['object_key']} and checking it");
            $obj = $store->object((int) $s['object_id']);
            $dump = $store->fetch($obj);
            exec('gzip -t ' . escapeshellarg($dump) . ' 2>&1', $o, $rc);
            if ($rc !== 0) throw new \RuntimeException('The backup file is damaged (gzip check failed).');
            $source = $this->capture($this->db->table('storage_backup_runs')->where('id', $s['run_id'])->get()->getRowArray());

            // 2. safety backup of the database as it is now
            if ($s['safety']) {
                $this->step($s, 'Taking a safety backup of the current database');
                $b = $this->db->table('storage_backups')->where('name', $s['backup'])->get()->getRowArray();
                $this->db->table('storage_backup_runs')->insert(['backup_id' => $b['id'], 'status' => 'running', 'trigger' => 'before restore', 'started_at' => date('Y-m-d H:i:s')]);
                $sid = (int) $this->db->insertID();
                (new StorageBackupService())->run($sid, false);
                $sr = $this->db->table('storage_backup_runs')->where('id', $sid)->get()->getRowArray();
                if ($sr['status'] !== 'ok') throw new \RuntimeException('The safety backup failed, so nothing was restored: ' . $sr['error']);
                $keep = $this->capture($sr);   // written back after the import (the import replaces these tables)
                $s['safety_key'] = $keep['object']['object_key']; self::save($s);
            }

            // 3. the import
            $this->step($s, 'Restoring the database (the Studio may answer slowly or with errors until this finishes)');
            $cfg = config('Database')->default;
            $cnf = tempnam(sys_get_temp_dir(), 'rs'); chmod($cnf, 0600);
            file_put_contents($cnf, sprintf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n", $cfg['username'], str_replace(["\n", '"'], '', $cfg['password']), $cfg['hostname'], (int) ($cfg['port'] ?: 3306)));
            $cmd = sprintf('set -o pipefail; gunzip -c %s | mysql --defaults-extra-file=%s %s', escapeshellarg($dump), escapeshellarg($cnf), escapeshellarg($cfg['database']));
            exec('bash -c ' . escapeshellarg($cmd) . ' 2>&1', $o2, $rc2);
            if ($rc2 !== 0) throw new \RuntimeException('The import stopped: ' . mb_substr(implode(' ', $o2), 0, 300) . ($keep ? " — restore the safety backup ({$s['safety_key']}) to go back." : ''));
            $this->db->reconnect();

            // 4. keep the safety backup (to undo this) and the restored backup listed
            $this->step($s, 'Re-listing the backups');
            if ($keep) $this->relist($keep);
            $this->relist($source);
            // migrations newer than the backup re-apply their tables
            exec(sprintf('cd %s && php8.3 spark migrate 2>&1', escapeshellarg(ROOTPATH)), $o3, $rc3);
            if ($rc3 !== 0) $s['log'][] = 'migrate after restore: ' . mb_substr(implode(' ', $o3), -300);

            $s['state'] = 'done'; $s['finished_at'] = date('Y-m-d H:i:s');
            $this->step($s, "Restored the database to {$s['taken_at']}");
            (new NotificationService())->notify(null, 'Database restored', "Restored to the backup of {$s['taken_at']} by {$s['by']}." . ($keep ? " The state before it is kept as {$s['safety_key']}." : ''), 'warning', 'storage');
        } catch (\Throwable $e) {
            $s['state'] = 'failed'; $s['error'] = mb_substr($e->getMessage(), 0, 500); $s['finished_at'] = date('Y-m-d H:i:s');
            $this->step($s, 'Failed: ' . $s['error']);
            try { (new NotificationService())->notify(null, 'Database restore failed', $s['error'], 'error', 'storage'); } catch (\Throwable) {}
        } finally {
            if ($dump) @unlink($dump);
            if ($cnf) @unlink($cnf);
        }
    }

    /** A backup run's rows: the run, its stored file and the file's copies. */
    private function capture(array $run): array
    {
        return ['run' => $run, 'object' => $this->db->table('storage_objects')->where('id', $run['object_id'])->get()->getRowArray(),
                'placements' => $this->db->table('storage_placements')->where('object_id', $run['object_id'])->get()->getResultArray()];
    }

    /** Write a captured backup back after an import, unless the restored data already has it (matched by file uuid). */
    private function relist(array $k): void
    {
        $o = $k['object']; unset($o['id']);
        $exists = $this->db->table('storage_objects')->where('uuid', $o['uuid'])->get()->getRowArray();
        if ($exists) return;
        $this->db->table('storage_objects')->insert($o);
        $oid = (int) $this->db->insertID();
        foreach ($k['placements'] as $p) {
            $this->db->query('INSERT IGNORE INTO storage_placements (object_id, node_id, status, verified_at, error, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$oid, $p['node_id'], $p['status'], $p['verified_at'], $p['error'], $p['created_at']]);
        }
        if ($this->db->table('storage_backups')->where('id', $k['run']['backup_id'])->countAllResults()) {
            $r = $k['run']; unset($r['id']); $r['object_id'] = $oid; $r['pid'] = null;
            $this->db->table('storage_backup_runs')->insert($r);
        }
    }
}
