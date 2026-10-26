<?php

namespace App\Commands;

use App\Services\NodeEnrolmentService;
use CodeIgniter\CLI\BaseCommand;

/**
 * `spark --no-header nodes:authorized-keys` — authorized_keys lines for the tunnel-only `nodetunnel` user.
 * Called by sshd through /usr/local/bin/aistudio-node-keys (AuthorizedKeysCommand); prints nothing else.
 */
class NodesAuthorizedKeys extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'nodes:authorized-keys';
    protected $description = 'Print authorized_keys lines for enrolled nodes (used by sshd).';

    public function run(array $params)
    {
        foreach ((new NodeEnrolmentService())->authorizedKeys() as $line) {
            echo $line, "\n";
        }
    }
}
