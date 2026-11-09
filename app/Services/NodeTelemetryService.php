<?php

namespace App\Services;

/**
 * NodeTelemetryService — live utilization a node agent reports with each heartbeat, and the
 * derived availability of every node.
 *
 * The agent posts (all optional): gpu_util, vram_used_mb, vram_total_mb, gpu_temp, cpu_util,
 * ram_used_mb, ram_total_mb, disk_used_gb, disk_total_gb, accelerator (cuda|rocm|cpu),
 * cuda_version, rocm_version, gpu_name, cpu_cores. The latest values land on `servers`; one
 * sample a minute goes to `server_metrics` (kept 7 days).
 */
class NodeTelemetryService
{
    public const ACCELERATORS = ['cuda', 'rocm', 'cpu'];
    public const DEPLOYMENTS  = ['local', 'remote', 'cloud', 'docker'];
    /** A node whose agent has not reported for this long counts as stale. */
    public const STALE_SECONDS = 180;
    /** Health checks run every 5 minutes; a result older than this is out of date. */
    public const CHECK_STALE_SECONDS = 900;
    public const KEEP_DAYS     = 7;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** Record one report. Returns the values stored on the server row. */
    public function record(array $server, array $in): array
    {
        $num = static function ($v, float $min, float $max): ?float {
            if (! is_numeric($v)) return null;
            $f = (float) $v;
            return ($f < $min || $f > $max) ? null : round($f, 1);
        };
        $mb2gb = static fn (?float $mb) => $mb === null ? null : round($mb / 1024, 1);

        $m = [
            'gpu_util_pct' => $num($in['gpu_util'] ?? null, 0, 100),
            'vram_used_gb' => $mb2gb($num($in['vram_used_mb'] ?? null, 0, 1e7)),
            'cpu_util_pct' => $num($in['cpu_util'] ?? null, 0, 100),
            'ram_used_gb'  => $mb2gb($num($in['ram_used_mb'] ?? null, 0, 1e8)),
            'disk_used_gb' => $num($in['disk_used_gb'] ?? null, 0, 1e7),
            'gpu_temp_c'   => $num($in['gpu_temp'] ?? null, 0, 150),
        ];
        $m = array_filter($m, static fn ($v) => $v !== null);
        $now = date('Y-m-d H:i:s');
        $upd = $m ? $m + ['metrics_at' => $now] : []; // a report with only hardware facts still fills them in
        // Hardware facts the agent can see fill in what the admin left blank.
        if (($acc = strtolower((string) ($in['accelerator'] ?? ''))) && in_array($acc, self::ACCELERATORS, true)) $upd['accelerator'] = $acc;
        if (($v = $mb2gb($num($in['vram_total_mb'] ?? null, 1, 1e7))) !== null) $upd['gpu_vram_gb'] = $v;
        if (($v = $mb2gb($num($in['ram_total_mb'] ?? null, 1, 1e8))) !== null && empty($server['ram_gb'])) $upd['ram_gb'] = $v;
        if (($v = $num($in['disk_total_gb'] ?? null, 1, 1e7)) !== null && empty($server['storage_gb'])) $upd['storage_gb'] = $v;
        if (($v = (int) ($in['cpu_cores'] ?? 0)) > 0 && $v < 4096 && empty($server['cpu_cores'])) $upd['cpu_cores'] = $v;
        foreach (['cuda_version' => 40, 'rocm_version' => 40, 'gpu_name' => 120] as $f => $len) {
            $v = trim(preg_replace('/[^\w .,:()\/+-]/', '', (string) ($in[$f] ?? '')));
            if ($v === '') continue;
            if ($f === 'gpu_name') { if (empty($server['gpu_model'])) $upd['gpu_model'] = mb_substr($v, 0, $len); }
            else $upd[$f] = mb_substr($v, 0, $len);
        }
        if (! $upd) return [];
        $this->db->table('servers')->where('id', $server['id'])->update($upd);
        if (! $m) return $upd;

        $last = $this->db->table('server_metrics')->select('recorded_at')->where('server_id', $server['id'])
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if (! $last || strtotime($last['recorded_at']) <= time() - 55) {
            $this->db->table('server_metrics')->insert(['server_id' => $server['id'], 'recorded_at' => $now] + $m);
            if (random_int(1, 50) === 1) $this->prune();
        }
        return $upd;
    }

    public function prune(): int
    {
        $this->db->table('server_metrics')->where('recorded_at <', date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400))->delete();
        return $this->db->affectedRows();
    }

    /** Samples for one node over the last $hours. */
    public function history(int $serverId, int $hours = 24): array
    {
        $rows = $this->db->table('server_metrics')
            ->select('gpu_util_pct, vram_used_gb, cpu_util_pct, ram_used_gb, disk_used_gb, gpu_temp_c, recorded_at')
            ->where('server_id', $serverId)->where('recorded_at >=', date('Y-m-d H:i:s', time() - $hours * 3600))
            ->orderBy('recorded_at', 'ASC')->get()->getResultArray();
        return array_map(static fn ($r) => array_map(static fn ($v) => is_numeric($v) ? (float) $v : $v, $r), $rows);
    }

    /**
     * available | busy | degraded | offline | disabled | unknown — what the scheduler and the
     * dashboard treat a node as right now.
     */
    public static function availability(array $s): string
    {
        if ((int) ($s['is_enabled'] ?? 1) !== 1) return 'disabled';
        $status = (string) ($s['status'] ?? 'unknown');
        // No health check result for 15 minutes: the status shown is out of date, so don't trust it.
        if ($status !== 'offline' && ! empty($s['last_heartbeat_at']) && strtotime($s['last_heartbeat_at']) < time() - self::CHECK_STALE_SECONDS) return 'unknown';
        if ($status === 'offline') return 'offline';
        if ($status === 'unknown') return 'unknown';
        if ($status === 'degraded') return 'degraded';
        $fresh = ! empty($s['metrics_at']) && strtotime($s['metrics_at']) >= time() - self::STALE_SECONDS;
        $vramPct = (! empty($s['gpu_vram_gb']) && isset($s['vram_used_gb'])) ? (float) $s['vram_used_gb'] / (float) $s['gpu_vram_gb'] * 100 : 0;
        if ($fresh && ((float) ($s['gpu_util_pct'] ?? 0) >= 90 || $vramPct >= 95 || ! empty($s['agent_job_id']))) return 'busy';
        return 'available';
    }
}
