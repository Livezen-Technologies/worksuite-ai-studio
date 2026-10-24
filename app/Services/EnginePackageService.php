<?php

namespace App\Services;

use RuntimeException;

/**
 * Signed offline model packages for the WorkSuite Sinhala AI Engine
 * (SRS §10.1 lifecycle, §14.5 manifest, FR ADM 002/003, FR TRNPIPE 005, SEC 007).
 *
 * Lifecycle: candidate → quarantined → verified → evaluated → approved → staged
 *            → active → retired; any state → revoked.
 *
 * A detached Ed25519 signature covers the exact UTF-8 manifest bytes. The
 * signing secret is read from the ENGINE_SIGNING_SECRET env var (base64, 64
 * bytes) or generated once into writable/engine-keys/ (chmod 600). It is never
 * returned by the API; only the public key and its key id are exposed.
 */
class EnginePackageService
{
    public const STATES = ['candidate', 'quarantined', 'verified', 'evaluated', 'approved', 'staged', 'active', 'retired', 'revoked'];

    /** from => allowed next states (revoked is allowed from everywhere, handled separately). */
    public const TRANSITIONS = [
        'candidate'   => ['quarantined'],
        'quarantined' => ['verified'],
        'verified'    => ['evaluated'],
        'evaluated'   => ['approved'],
        'approved'    => ['staged'],
        'staged'      => ['active'],
        'active'      => ['retired'],
        'retired'     => [],
        'revoked'     => [],
    ];

    /** Licences that block commercial bundling (SRS §3.2: MMS is CC BY-NC). */
    public const BLOCKED_LICENSES = ['CC-BY-NC-4.0', 'CC-BY-NC-SA-4.0', 'CC-BY-NC-ND-4.0', 'UNKNOWN'];

    public const RUNTIMES = ['ollama', 'faster-whisper', 'tts-runtime', 'rules'];

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // ---------------------------------------------------------------- validation

    /** SEC 007 / FR ADM 002 checks on a declared artifact list. Returns list of problems. */
    public function validateArtifacts(array $artifacts): array
    {
        $errors = [];
        $seen   = [];
        if ($artifacts === []) $errors[] = 'At least one artifact is required.';
        foreach ($artifacts as $i => $a) {
            $path = (string) ($a['path'] ?? '');
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
                || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('/^[A-Za-z]:/', $path)) {
                $errors[] = "Artifact #{$i}: path must be relative with no traversal.";
            }
            if (preg_match('/\.(exe|dll|so|dylib|sh|bat|cmd|ps1|py|js)$/i', $path)) {
                $errors[] = "Artifact #{$i}: executable files are not allowed in model packages.";
            }
            if (isset($seen[strtolower($path)])) $errors[] = "Artifact #{$i}: duplicate path.";
            $seen[strtolower($path)] = true;
            if (! preg_match('/^[a-f0-9]{64}$/', (string) ($a['sha256'] ?? ''))) {
                $errors[] = "Artifact #{$i}: sha256 must be 64 lowercase hex characters.";
            }
            if (! isset($a['size_bytes']) || ! is_numeric($a['size_bytes']) || (int) $a['size_bytes'] <= 0) {
                $errors[] = "Artifact #{$i}: measured size_bytes is required.";
            }
        }
        return $errors;
    }

    // ---------------------------------------------------------------- lifecycle

    /**
     * Move a package to the next state, enforcing the evidence each step needs.
     * Returns the updated row; throws RuntimeException with a user-facing reason.
     */
    public function transition(array $pkg, string $to, int $actor, ?string $reason = null): array
    {
        $from = $pkg['state'];
        if ($to !== 'revoked' && ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new RuntimeException("Cannot move a package from {$from} to {$to}.");
        }
        if ($to === 'revoked' && $from === 'revoked') {
            throw new RuntimeException('Package is already revoked.');
        }

        $upd = ['state' => $to];

        switch ($to) {
            case 'verified':
                if ($errs = $this->validateArtifacts(json_decode($pkg['artifacts'], true) ?: [])) {
                    throw new RuntimeException(implode(' ', $errs));
                }
                if (in_array(strtoupper($pkg['license_id']), array_map('strtoupper', self::BLOCKED_LICENSES), true)) {
                    throw new RuntimeException('Licence ' . $pkg['license_id'] . ' is not approved for commercial distribution.');
                }
                if (empty($pkg['rights_approval_id'])) {
                    throw new RuntimeException('A rights approval reference is required before verification.');
                }
                break;

            case 'evaluated':
                $ev = $pkg['evaluation_id'] ? $this->db->table('engine_evaluations')->where('id', $pkg['evaluation_id'])->get()->getRowArray() : null;
                if (! $ev) throw new RuntimeException('Attach an evaluation report first.');
                if ($ev['task'] !== $pkg['task']) throw new RuntimeException('Evaluation task does not match package task.');
                break;

            case 'approved':
                $ev = $this->db->table('engine_evaluations')->where('id', $pkg['evaluation_id'])->get()->getRowArray();
                if (! $ev || $ev['decision'] !== 'pass') throw new RuntimeException('The evaluation has not passed its release gate.');
                if (! $ev['release_approver'] || ! $ev['language_approver']) {
                    throw new RuntimeException('Release owner and language lead must both approve the evaluation (§17.4).');
                }
                break;

            case 'staged':
                $signed = $this->sign($pkg);
                $upd    = array_merge($upd, $signed);
                break;

            case 'active':
                // Atomic binding swap: the previously active package for the task is retired.
                $this->db->transStart();
                foreach ($this->db->table('engine_packages')->where(['task' => $pkg['task'], 'state' => 'active'])->get()->getResultArray() as $old) {
                    $this->db->table('engine_packages')->where('id', $old['id'])->update([
                        'state'         => 'retired',
                        'state_history' => $this->history($old, 'active', 'retired', $actor, 'superseded by ' . $pkg['package_id']),
                        'updated_at'    => date('Y-m-d H:i:s'),
                    ]);
                }
                $this->db->table('engine_packages')->where('id', $pkg['id'])->update([
                    'state' => 'active', 'state_history' => $this->history($pkg, $from, 'active', $actor, $reason), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->transComplete();
                return $this->db->table('engine_packages')->where('id', $pkg['id'])->get()->getRowArray();
        }

        $upd['state_history'] = $this->history($pkg, $from, $to, $actor, $reason);
        $upd['updated_at']    = date('Y-m-d H:i:s');
        $this->db->table('engine_packages')->where('id', $pkg['id'])->update($upd);
        return $this->db->table('engine_packages')->where('id', $pkg['id'])->get()->getRowArray();
    }

    /** Roll back: re-activate the most recent retired, non-revoked package for the task (FR ADM 004). */
    public function rollback(string $task, int $actor): array
    {
        $prev = $this->db->table('engine_packages')->where(['task' => $task, 'state' => 'retired'])
            ->where('signature IS NOT NULL')->orderBy('updated_at', 'DESC')->get()->getRowArray();
        if (! $prev) throw new RuntimeException('No retained version is available for rollback.');
        $prev['state'] = 'staged'; // re-enter through the activation path
        return $this->transition($prev, 'active', $actor, 'rollback');
    }

    private function history(array $pkg, string $from, string $to, int $actor, ?string $reason): string
    {
        $h   = json_decode($pkg['state_history'] ?? '[]', true) ?: [];
        $h[] = ['from' => $from, 'to' => $to, 'actor' => $actor, 'reason' => $reason, 'at' => gmdate('c')];
        return json_encode($h);
    }

    // ---------------------------------------------------------------- manifest + signing

    /** Canonical manifest (SRS §14.5), serialised once; these exact bytes are signed. */
    public function buildManifest(array $pkg): string
    {
        $ev  = $pkg['evaluation_id'] ? $this->db->table('engine_evaluations')->where('id', $pkg['evaluation_id'])->get()->getRowArray() : null;
        $job = $pkg['training_job_id'] ? $this->db->table('training_jobs')->where('id', $pkg['training_job_id'])->get()->getRowArray() : null;

        $manifest = [
            'schema_version'     => 1,
            'package_id'         => $pkg['package_id'],
            'version'            => $pkg['version'],
            'task'               => $pkg['task'],
            'languages'          => array_values(array_filter(explode(',', $pkg['languages']))),
            'runtime'            => ['name' => $pkg['runtime_name'], 'version_range' => $pkg['runtime_range']],
            'base_model'         => $pkg['base_model'],
            'quantization'       => $pkg['quantization'],
            'artifacts'          => json_decode($pkg['artifacts'], true),
            'license_id'         => $pkg['license_id'],
            'license_file'       => 'LICENSE.txt',
            'rights_approval_id' => $pkg['rights_approval_id'],
            'evaluation_id'      => $ev['uuid'] ?? null,
            'min_ram_gib'        => $pkg['min_ram_gib'] !== null ? (float) $pkg['min_ram_gib'] : null,
            'min_disk_gib'       => $pkg['min_disk_gib'] !== null ? (float) $pkg['min_disk_gib'] : null,
            'training_run_id'    => $job ? (string) $job['id'] : null,
            'publisher_key_id'   => $this->keyId(),
        ];
        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function sign(array $pkg): array
    {
        $bytes = $this->buildManifest($pkg);
        $sig   = sodium_crypto_sign_detached($bytes, $this->secretKey());
        return [
            'manifest'         => $bytes,
            'manifest_sha256'  => hash('sha256', $bytes),
            'signature'        => base64_encode($sig),
            'publisher_key_id' => $this->keyId(),
        ];
    }

    public function verify(array $pkg): bool
    {
        if (empty($pkg['manifest']) || empty($pkg['signature'])) return false;
        return sodium_crypto_sign_verify_detached(base64_decode($pkg['signature']), $pkg['manifest'], $this->publicKey());
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 16);
    }

    private function secretKey(): string
    {
        $env = getenv('ENGINE_SIGNING_SECRET') ?: ($_ENV['ENGINE_SIGNING_SECRET'] ?? '');
        if ($env !== '') {
            $sk = base64_decode($env, true);
            if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new RuntimeException('ENGINE_SIGNING_SECRET is not a valid base64 Ed25519 secret key.');
            }
            return $sk;
        }
        $dir  = WRITEPATH . 'engine-keys';
        $file = $dir . '/publisher.sk';
        if (! is_file($file)) {
            if (! is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($file, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
            chmod($file, 0600);
        }
        return base64_decode(trim(file_get_contents($file)));
    }
}
