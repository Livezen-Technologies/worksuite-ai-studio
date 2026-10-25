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

    public const RUNTIMES = ['ollama', 'faster-whisper', 'tts-runtime', 'rules', 'onnxruntime', 'pytorch'];

    /** Device profiles a package can be bound to (engine_bindings.profile). */
    public const PROFILES = ['minimum', 'standard', 'apple', 'accelerated'];

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
     * The evidence a move to $to still lacks, as user-facing strings. Has no
     * side effects: transition() refuses with exactly these reasons, and the
     * package detail reports them as `missing` before anyone presses a button.
     */
    public function check(array $pkg, string $to): array
    {
        $from = $pkg['state'];
        if ($to === 'revoked') {
            return $from === 'revoked' ? ['Package is already revoked.'] : [];
        }
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return ["Cannot move a package from {$from} to {$to}."];
        }

        $missing = [];
        switch ($to) {
            case 'verified':
                $missing = $this->validateArtifacts(json_decode((string) $pkg['artifacts'], true) ?: []);
                if (in_array(strtoupper($pkg['license_id']), array_map('strtoupper', self::BLOCKED_LICENSES), true)) {
                    $missing[] = 'Licence ' . $pkg['license_id'] . ' is not approved for commercial distribution.';
                }
                if (empty($pkg['rights_approval_id'])) {
                    $missing[] = 'A rights approval reference is required before verification.';
                }
                break;

            case 'evaluated':
                $ev = $this->evaluation($pkg);
                if (! $ev) $missing[] = 'Attach an evaluation report first.';
                elseif ($ev['task'] !== $pkg['task']) $missing[] = 'Evaluation task does not match package task.';
                break;

            case 'approved':
                $ev = $this->evaluation($pkg);
                if (! $ev || $ev['decision'] !== 'pass') $missing[] = 'The evaluation has not passed its release gate.';
                if (! $ev || ! $ev['release_approver'] || ! $ev['language_approver']) {
                    $missing[] = 'Release owner and language lead must both approve the evaluation (§17.4).';
                }
                break;

            case 'active':
                if (empty($pkg['signature']) || empty($pkg['manifest'])) $missing[] = 'Package is not signed.';
                break;
        }
        return $missing;
    }

    /**
     * Move a package to the next state, enforcing the evidence each step needs.
     * Returns the updated row; throws RuntimeException with a user-facing reason.
     * → active binds the package to the task's `standard` profile (see activate()).
     */
    public function transition(array $pkg, string $to, int $actor, ?string $reason = null): array
    {
        if ($missing = $this->check($pkg, $to)) {
            throw new RuntimeException(implode(' ', $missing));
        }
        if ($to === 'active') {
            $this->activate($pkg['task'], 'standard', (int) $pkg['id'], $actor, $reason);
            return $this->find((int) $pkg['id']);
        }

        $from = $pkg['state'];
        $upd  = ['state' => $to];
        if ($to === 'staged') {
            $upd = array_merge($upd, $this->sign($pkg));
        }
        $upd['state_history'] = $this->history($pkg, $from, $to, $actor, $reason);
        $upd['updated_at']    = date('Y-m-d H:i:s');
        $this->db->table('engine_packages')->where('id', $pkg['id'])->update($upd);
        return $this->find((int) $pkg['id']);
    }

    /**
     * Task-wide roll back on the `standard` profile (FR ADM 004): swap to the
     * binding's previous package, or — for packages activated before bindings
     * existed — re-activate the most recent retired, signed package for the task.
     */
    public function rollback(string $task, int $actor): array
    {
        $b = $this->binding($task, 'standard');
        if ($b && $b['previous_package_id']) {
            $this->rollbackBinding($task, 'standard', $actor);
            return $this->find((int) $b['previous_package_id']);
        }
        $prev = $this->db->table('engine_packages')->where(['task' => $task, 'state' => 'retired'])
            ->where('signature IS NOT NULL')->orderBy('updated_at', 'DESC')->get()->getRowArray();
        if (! $prev) throw new RuntimeException('No retained version is available for rollback.');
        $this->activate($task, 'standard', (int) $prev['id'], $actor, 'rollback', true);
        return $this->find((int) $prev['id']);
    }

    // ---------------------------------------------------------------- bindings (FR ADM 003 / 004)

    public function binding(string $task, string $profile): ?array
    {
        return $this->db->table('engine_bindings')->where(['task' => $task, 'profile' => $profile])->get()->getRowArray() ?: null;
    }

    /**
     * Bind a signed package as the active one for task × profile. The current
     * active becomes previous; a package left active nowhere is retired. One
     * transaction. $allowRetired is for roll back onto a retained version.
     */
    public function activate(string $task, string $profile, int $packageId, int $actor, ?string $reason = null, bool $allowRetired = false): void
    {
        if (! in_array($profile, self::PROFILES, true)) throw new RuntimeException('Unknown profile.');
        $pkg = $this->find($packageId);
        if (! $pkg) throw new RuntimeException('Package not found.');
        if ($pkg['task'] !== $task) throw new RuntimeException("Package is for {$pkg['task']}, not {$task}.");
        $okStates = $allowRetired ? ['staged', 'active', 'retired'] : ['staged', 'active'];
        if (! in_array($pkg['state'], $okStates, true)) {
            throw new RuntimeException("Only signed (staged or active) packages can be activated; this one is {$pkg['state']}.");
        }
        if (empty($pkg['signature']) || ! $this->verify($pkg)) throw new RuntimeException('Package signature does not verify.');

        $cur = $this->binding($task, $profile);
        if ($cur && (int) $cur['active_package_id'] === $packageId) {
            throw new RuntimeException('That package is already active for this profile.');
        }
        $now = date('Y-m-d H:i:s');

        $this->db->transStart();
        $row = ['active_package_id' => $packageId, 'previous_package_id' => $cur['active_package_id'] ?? null, 'updated_by' => $actor ?: null, 'updated_at' => $now];
        if ($cur) $this->db->table('engine_bindings')->where('id', $cur['id'])->update($row);
        else $this->db->table('engine_bindings')->insert($row + ['task' => $task, 'profile' => $profile]);

        if ($pkg['state'] !== 'active') {
            $this->db->table('engine_packages')->where('id', $packageId)->update([
                'state' => 'active', 'state_history' => $this->history($pkg, $pkg['state'], 'active', $actor, $reason ?? "bound to {$task}/{$profile}"), 'updated_at' => $now,
            ]);
        }
        if (! empty($cur['active_package_id'])) $this->retireIfUnbound((int) $cur['active_package_id'], $actor, 'superseded by ' . $pkg['package_id']);
        $this->db->transComplete();
        if (! $this->db->transStatus()) throw new RuntimeException('Activation failed; nothing was changed.');
    }

    /** Swap active and previous for task × profile. */
    public function rollbackBinding(string $task, string $profile, int $actor): void
    {
        $b = $this->binding($task, $profile);
        if (! $b || ! $b['previous_package_id']) throw new RuntimeException('There is no previous package to roll back to.');
        $prev = $this->find((int) $b['previous_package_id']);
        if (! $prev || $prev['state'] === 'revoked') throw new RuntimeException('The previous package has been revoked.');
        if (empty($prev['signature']) || ! $this->verify($prev)) throw new RuntimeException('The previous package is no longer signed.');
        $now = date('Y-m-d H:i:s');

        $this->db->transStart();
        $this->db->table('engine_bindings')->where('id', $b['id'])->update([
            'active_package_id' => $b['previous_package_id'], 'previous_package_id' => $b['active_package_id'], 'updated_by' => $actor ?: null, 'updated_at' => $now,
        ]);
        if ($prev['state'] !== 'active') {
            $this->db->table('engine_packages')->where('id', $prev['id'])->update([
                'state' => 'active', 'state_history' => $this->history($prev, $prev['state'], 'active', $actor, "rollback {$task}/{$profile}"), 'updated_at' => $now,
            ]);
        }
        if ($b['active_package_id']) $this->retireIfUnbound((int) $b['active_package_id'], $actor, "rolled back {$task}/{$profile}");
        $this->db->transComplete();
        if (! $this->db->transStatus()) throw new RuntimeException('Roll back failed; nothing was changed.');
    }

    private function retireIfUnbound(int $id, int $actor, string $reason): void
    {
        if ($this->db->table('engine_bindings')->where('active_package_id', $id)->countAllResults()) return;
        $old = $this->find($id);
        if (! $old || $old['state'] !== 'active') return;
        $this->db->table('engine_packages')->where('id', $id)->update([
            'state' => 'retired', 'state_history' => $this->history($old, 'active', 'retired', $actor, $reason), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ---------------------------------------------------------------- server-side artifacts

    public function artifactDir(array $pkg): string
    {
        return WRITEPATH . 'engine-artifacts/' . $pkg['package_id'] . '/';
    }

    /**
     * Store an uploaded file as an artifact. The upload is moved (never read
     * into memory), hashed with hash_file, and the artifact list revalidated
     * with the same safe-import rules before it replaces any same-path entry.
     */
    public function putArtifact(array $pkg, string $tmpFile, string $path): array
    {
        if (! in_array($pkg['state'], ['candidate', 'quarantined'], true)) {
            throw new RuntimeException('Artifacts can only change while the package is candidate or quarantined.');
        }
        $path = trim(str_replace('\\', '/', $path));
        $list = array_values(array_filter(json_decode((string) $pkg['artifacts'], true) ?: [], fn ($a) => strtolower((string) $a['path']) !== strtolower($path)));

        $sha  = hash_file('sha256', $tmpFile);
        $size = filesize($tmpFile);
        $list[] = ['path' => $path, 'sha256' => $sha, 'size_bytes' => (int) $size, 'server_verified' => true];
        if ($errs = $this->validateArtifacts($list)) throw new RuntimeException(implode(' ', $errs));

        $dest = $this->artifactDir($pkg) . $path;
        if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0750, true)) throw new RuntimeException('Could not create the artifact folder.');
        if (! rename($tmpFile, $dest) && ! (copy($tmpFile, $dest) && unlink($tmpFile))) {
            throw new RuntimeException('Could not store the artifact.');
        }
        return $this->saveArtifacts($pkg, $list);
    }

    public function removeArtifact(array $pkg, string $path): array
    {
        if (! in_array($pkg['state'], ['candidate', 'quarantined'], true)) {
            throw new RuntimeException('Artifacts can only change while the package is candidate or quarantined.');
        }
        $list = json_decode((string) $pkg['artifacts'], true) ?: [];
        $keep = array_values(array_filter($list, fn ($a) => (string) $a['path'] !== $path));
        if (count($keep) === count($list)) throw new RuntimeException('No artifact with that path.');
        if (! $this->validateArtifacts([['path' => $path, 'sha256' => str_repeat('0', 64), 'size_bytes' => 1]])) {
            $file = $this->artifactDir($pkg) . $path;
            if (is_file($file)) unlink($file);
        }
        return $this->saveArtifacts($pkg, $keep);
    }

    private function saveArtifacts(array $pkg, array $list): array
    {
        $this->db->table('engine_packages')->where('id', $pkg['id'])->update(['artifacts' => json_encode(array_values($list)), 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->find((int) $pkg['id']);
    }

    public function find(int $id): ?array
    {
        return $this->db->table('engine_packages')->where('id', $id)->get()->getRowArray() ?: null;
    }

    private function evaluation(array $pkg): ?array
    {
        return $pkg['evaluation_id'] ? ($this->db->table('engine_evaluations')->where('id', $pkg['evaluation_id'])->get()->getRowArray() ?: null) : null;
    }

    private function history(array $pkg, string $from, string $to, int $actor, ?string $reason): string
    {
        $h   = json_decode($pkg['state_history'] ?? '[]', true) ?: [];
        $h[] = ['from' => $from, 'to' => $to, 'actor' => $actor, 'by' => $actor, 'reason' => $reason, 'at' => gmdate('c')];
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
