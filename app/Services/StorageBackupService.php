<?php

namespace App\Services;

/**
 * Database backups into the object store (storage part 3).
 *
 * A backup is a gzipped mysqldump (--single-transaction, so the Studio keeps working) stored as a file in app
 * "backups" under <backup name>/<date>.sql.gz, placed by the backup's policy — point it at a policy with copies in two
 * regions for off-site recovery. Older runs beyond `keep` are deleted. The scheduler (`storage_backups`, every 5 min)
 * starts due backups as a detached `spark storage:backup <run>` (a dump of a large database takes far longer than one
 * scheduler tick) and marks runs whose process died as failed.
 */
class StorageBackupService
{
    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public static function slug(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'backup';
    }

    /** Scheduler tick: reap dead runs, start due ones. */
    public function tick(): array
    {
        $out = ['started' => [], 'reaped' => 0];
        foreach ($this->db->table('storage_backup_runs')->where('status', 'running')->get()->getResultArray() as $r) {
            if ($r['pid'] && ! file_exists('/proc/' . (int) $r['pid'])) {
                $this->finish((int) $r['id'], 'failed', 'The backup process ended without finishing (killed or out of memory).');
                $out['reaped']++;
            }
        }
        $now = time();
        foreach ($this->db->table('storage_backups')->where('enabled', 1)->get()->getResultArray() as $b) {
            if ((int) date('G', $now) < (int) $b['at_hour']) continue;
            $last = $b['last_run_at'] ? strtotime($b['last_run_at']) : 0;
            $due = $b['schedule'] === 'weekly' ? $now - $last > 6.5 * 86400 : date('Y-m-d', $last) !== date('Y-m-d', $now);
            if ($due && ! $this->running((int) $b['id'])) $out['started'][] = $this->start($b, 'schedule');
        }
        return $out;
    }

    private function running(int $backupId): bool
    {
        return (bool) $this->db->table('storage_backup_runs')->where(['backup_id' => $backupId, 'status' => 'running'])->countAllResults();
    }

    public function start(array $b, string $trigger): int
    {
        if ($this->running((int) $b['id'])) throw new \RuntimeException('This backup is already running.');
        $now = date('Y-m-d H:i:s');
        $this->db->table('storage_backup_runs')->insert(['backup_id' => $b['id'], 'status' => 'running', 'trigger' => $trigger, 'started_at' => $now]);
        $run = (int) $this->db->insertID();
        $this->db->table('storage_backups')->where('id', $b['id'])->update(['last_run_at' => $now]);
        $log = WRITEPATH . "logs/storage-backup-{$run}.log";
        $pid = (int) trim((string) shell_exec(sprintf('cd %s && setsid nohup php8.3 spark storage:backup %d > %s 2>&1 < /dev/null & echo $!',
            escapeshellarg(ROOTPATH), $run, escapeshellarg($log))));
        $this->db->table('storage_backup_runs')->where('id', $run)->update(['pid' => $pid ?: null]);
        return $run;
    }

    /** The detached process: dump, store, prune (not for a restore's safety backup: it must never prune the backup being restored). */
    public function run(int $runId, bool $prune = true): void
    {
        $r = $this->db->table('storage_backup_runs')->where('id', $runId)->get()->getRowArray();
        $b = $r ? $this->db->table('storage_backups')->where('id', $r['backup_id'])->get()->getRowArray() : null;
        if (! $b || $r['status'] !== 'running') return;
        $this->db->table('storage_backup_runs')->where('id', $runId)->update(['pid' => getmypid()]);
        $cfg = config('Database')->default;
        $cnf = tempnam(sys_get_temp_dir(), 'bk'); $dump = tempnam(sys_get_temp_dir(), 'bkd');
        try {
            chmod($cnf, 0600);   // the password goes to a private file, never on a command line
            file_put_contents($cnf, sprintf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n", $cfg['username'], str_replace(["\n", '"'], '', $cfg['password']), $cfg['hostname'], (int) ($cfg['port'] ?: 3306)));
            $ignore = '';
            foreach (array_filter(array_map('trim', explode(',', (string) $b['exclude_tables']))) as $t) {
                if (preg_match('/^\w+$/', $t)) $ignore .= ' --ignore-table=' . escapeshellarg($cfg['database'] . '.' . $t);
            }
            $cmd = sprintf('set -o pipefail; mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers --no-tablespaces%s %s | gzip -6 > %s',
                escapeshellarg($cnf), $ignore, escapeshellarg($cfg['database']), escapeshellarg($dump));
            exec('bash -c ' . escapeshellarg($cmd) . ' 2>&1', $o, $rc);
            if ($rc !== 0) throw new \RuntimeException('mysqldump failed: ' . mb_substr(implode(' ', $o), 0, 300));
            if (filesize($dump) < 100) throw new \RuntimeException('The dump came out empty.');
            $slug = self::slug($b['name']);
            $o = (new ObjectStoreService())->put('backups', $slug . '/' . date('Y-m-d_His') . '.sql.gz', $dump, null, $b['policy_id'] ? (int) $b['policy_id'] : null, null, 'application/gzip');
            $this->db->table('storage_backup_runs')->where('id', $runId)->update(['object_id' => $o['id'], 'size' => (int) $o['size']]);
            $this->finish($runId, 'ok');
            if ($prune) $this->prune($b);
        } catch (\Throwable $e) {
            $this->finish($runId, 'failed', $e->getMessage());
        } finally {
            @unlink($cnf); @unlink($dump);
        }
    }

    /** Keep the newest `keep` stored dumps of this backup. */
    private function prune(array $b): void
    {
        $rows = $this->db->table('storage_objects')->select('id')->where(['app' => 'backups', 'deleted_at' => null])
            ->like('object_key', self::slug($b['name']) . '/', 'after')->orderBy('id', 'DESC')->get()->getResultArray();
        $store = new ObjectStoreService();
        foreach (array_slice($rows, max(1, (int) $b['keep'])) as $o) $store->remove((int) $o['id'], 'older than the backup keeps');
    }

    private function finish(int $runId, string $status, ?string $error = null): void
    {
        $this->db->table('storage_backup_runs')->where('id', $runId)->update(['status' => $status, 'error' => $error ? mb_substr($error, 0, 500) : null, 'finished_at' => date('Y-m-d H:i:s')]);
        if ($status === 'failed') {
            $name = $this->db->table('storage_backup_runs r')->select('b.name')->join('storage_backups b', 'b.id = r.backup_id')->where('r.id', $runId)->get()->getRowArray()['name'] ?? 'backup';
            $ns = new NotificationService();
            $ns->notify(null, "Backup failed: {$name}", (string) $error, 'error', 'storage');
            try { $ns->dispatch("Backup failed: {$name}", (string) $error); } catch (\Throwable) {}
        }
    }
}
