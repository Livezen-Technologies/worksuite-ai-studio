<?php

namespace App\Commands;

use App\Services\StorageService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** `spark storage:check [node id]` — run storage health checks now (the scheduler does it every 5 minutes). */
class StorageCheck extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'storage:check';
    protected $description = 'Check one storage node, or every node that is not retired, and evaluate alert rules.';
    protected $usage       = 'storage:check [node id]';

    public function run(array $params)
    {
        $svc = new StorageService();
        $db = \Config\Database::connect();
        $nodes = isset($params[0]) ? $db->table('storage_nodes')->where('id', (int) $params[0])->where('deleted_at', null)->get()->getResultArray()
            : $db->table('storage_nodes')->where('deleted_at', null)->where('status !=', 'retired')->get()->getResultArray();
        if (! $nodes) { CLI::write('No storage nodes to check.', 'yellow'); return EXIT_SUCCESS; }
        foreach ($nodes as $n) {
            $r = $svc->check($n);
            $cap = $r['capacity_bytes'] ? sprintf('%.1f of %.1f GB (%s%%)', $r['used_bytes'] / 1073741824, $r['capacity_bytes'] / 1073741824, $r['used_pct']) : 'no capacity';
            CLI::write(sprintf('#%d %-24s %-9s %s · write %s MB/s · read %s MB/s · %s ms%s', $r['id'], $r['name'], $r['health'], $cap,
                $r['write_mbps'] ?? '—', $r['read_mbps'] ?? '—', $r['latency_ms'] ?? '—', $r['last_error'] ? ' — ' . $r['last_error'] : ''),
                $r['health'] === 'down' ? 'red' : ($r['health'] === 'ok' ? 'green' : 'yellow'));
        }
        return EXIT_SUCCESS;
    }
}
