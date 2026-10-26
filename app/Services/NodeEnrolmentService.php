<?php

namespace App\Services;

use App\Models\ServerModel;

/**
 * NodeEnrolmentService — one-time tokens that turn a machine into an Ollama node.
 *
 *  1. An admin issues a token on the Servers page (shown once; only its sha256 is stored).
 *  2. The `aistudio-node` tool on the machine sends the token, its ed25519 public key and specs to
 *     POST /api/nodes/enrol. The token is consumed, a server row is created with its own tunnel port,
 *     and the tool gets back what it needs to open `ssh -R 127.0.0.1:{port}:127.0.0.1:11434 nodetunnel@host`.
 *  3. sshd asks `spark nodes:authorized-keys` for the keys of the `nodetunnel` user; each key may only
 *     listen on its own port. Removing the server revokes the key on the next connection attempt.
 */
class NodeEnrolmentService
{
    public const PORT_MIN    = 11501;
    public const PORT_MAX    = 11599;
    public const TUNNEL_USER = 'nodetunnel';

    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** Issue a token valid for $hours. Returns the plain token (never stored) and its expiry. */
    public function issue(int $userId, ?string $label, int $hours = 24): array
    {
        $token   = 'aisn_' . bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + max(1, min($hours, 168)) * 3600);
        $this->db->table('node_enrolments')->insert([
            'token_hash' => hash('sha256', $token),
            'label'      => $label ? mb_substr($label, 0, 120) : null,
            'created_by' => $userId ?: null,
            'expires_at' => $expires,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * Consume a token and register the node. Throws \InvalidArgumentException with a user-facing message.
     *
     * @param array $info name, hostname, os, gpu_model, cuda_version, cpu_cores, ram_gb, ollama_version
     */
    public function enrol(string $token, string $publicKey, array $info): array
    {
        [$key, $fingerprint] = $this->parseKey($publicKey);

        // One enrolment at a time, so two nodes never get the same port.
        $this->db->query("SELECT GET_LOCK('aistudio_node_enrol', 10)");
        try {
            $row = $this->db->table('node_enrolments')->where('token_hash', hash('sha256', trim($token)))->get()->getRowArray();
            if (! $row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) {
                throw new \InvalidArgumentException('This enrolment token is invalid, already used or expired. Create a new one on the Servers page.');
            }
            $servers = new ServerModel();
            if ($servers->where('ssh_fingerprint', $fingerprint)->first()) {
                throw new \InvalidArgumentException('This machine is already enrolled. Remove it on the Servers page before enrolling it again.');
            }

            $port = $this->freePort();
            $name = trim((string) ($info['name'] ?? '')) ?: ($row['label'] ?: (trim((string) ($info['hostname'] ?? '')) ?: 'Node'));
            $now  = date('Y-m-d H:i:s');
            $id   = $servers->insert([
                'name'            => mb_substr($name, 0, 120),
                'description'     => 'Enrolled with aistudio-node; reverse SSH tunnel on port ' . $port . '.',
                'kind'            => 'ollama',
                'hostname'        => $this->clip($info['hostname'] ?? null, 255),
                'api_url'         => 'http://127.0.0.1:' . $port,
                'port'            => $port,
                'tunnel_port'     => $port,
                'ssh_public_key'  => $key,
                'ssh_fingerprint' => $fingerprint,
                'enrolled_at'     => $now,
                'gpu_model'       => $this->clip($info['gpu_model'] ?? null, 120),
                'cuda_version'    => $this->clip($info['cuda_version'] ?? null, 20),
                'cpu_cores'       => is_numeric($info['cpu_cores'] ?? null) ? (int) $info['cpu_cores'] : null,
                'ram_gb'          => is_numeric($info['ram_gb'] ?? null) ? round((float) $info['ram_gb'], 1) : null,
                'os'              => $this->clip($info['os'] ?? null, 60),
                'ollama_version'  => $this->clip($info['ollama_version'] ?? null, 20),
                'is_local'        => 0,
                'status'          => 'unknown',
            ], true);
            if (! $id) {
                throw new \InvalidArgumentException('Could not register the node: ' . implode(' ', $servers->errors()));
            }
            $this->db->table('node_enrolments')->where('id', $row['id'])->update(['used_at' => $now, 'server_id' => $id]);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('aistudio_node_enrol')");
        }

        return [
            'server_id'   => (int) $id,
            'name'        => $name,
            'tunnel_port' => $port,
            'ssh_user'    => self::TUNNEL_USER,
            'ssh_host'    => $this->sshHost(),
            'ssh_port'    => (int) (env('NODE_TUNNEL_SSH_PORT') ?: 22),
            'known_host'  => $this->knownHost(),
        ];
    }

    /** authorized_keys lines for the tunnel user: every enrolled, not removed node, limited to its own port. */
    public function authorizedKeys(): array
    {
        $rows = (new ServerModel())->select('id, tunnel_port, ssh_public_key')
            ->where('ssh_public_key IS NOT NULL')->where('tunnel_port IS NOT NULL')->findAll();
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = sprintf('restrict,port-forwarding,permitlisten="127.0.0.1:%d" %s aistudio-node-%d', $r['tunnel_port'], $r['ssh_public_key'], $r['id']);
        }
        return $lines;
    }

    /** Accept a single ssh-ed25519 key; returns [normalised "type base64", SHA256 fingerprint]. */
    private function parseKey(string $publicKey): array
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        if (count($parts) < 2 || $parts[0] !== 'ssh-ed25519') {
            throw new \InvalidArgumentException('An ssh-ed25519 public key is required.');
        }
        $blob = base64_decode($parts[1], true);
        // Wire format: uint32 len + "ssh-ed25519" + uint32 len + 32-byte key.
        if ($blob === false || strlen($blob) !== 51 || substr($blob, 4, 11) !== 'ssh-ed25519') {
            throw new \InvalidArgumentException('The public key is not a valid ssh-ed25519 key.');
        }
        return [$parts[0] . ' ' . $parts[1], 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=')];
    }

    private function freePort(): int
    {
        $used = array_map('intval', array_column(
            (new ServerModel())->select('tunnel_port')->where('tunnel_port IS NOT NULL')->findAll(), 'tunnel_port'));
        for ($p = self::PORT_MIN; $p <= self::PORT_MAX; $p++) {
            if (! in_array($p, $used, true)) {
                return $p;
            }
        }
        throw new \InvalidArgumentException('No free tunnel ports are left. Remove unused nodes first.');
    }

    /** Host the node connects to over SSH. The web host may sit behind a proxy, so it can be set separately. */
    private function sshHost(): string
    {
        return (string) (env('NODE_TUNNEL_HOST') ?: parse_url((string) config('App')->baseURL, PHP_URL_HOST));
    }

    /** known_hosts line pinning this server's ed25519 host key, so the tool never trusts on first use. */
    private function knownHost(): ?string
    {
        $pub = @file_get_contents('/etc/ssh/ssh_host_ed25519_key.pub');
        if (! $pub) {
            return null;
        }
        $port = (int) (env('NODE_TUNNEL_SSH_PORT') ?: 22);
        $host = $port === 22 ? $this->sshHost() : '[' . $this->sshHost() . ']:' . $port;
        $p    = preg_split('/\s+/', trim($pub));
        return $host . ' ' . $p[0] . ' ' . $p[1];
    }

    private function clip($v, int $len): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $len);
    }
}
