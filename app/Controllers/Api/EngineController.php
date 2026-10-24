<?php

namespace App\Controllers\Api;

use App\Services\EngineDatasetService;
use App\Services\EngineGateService;
use App\Services\EnginePackageService;
use RuntimeException;

/**
 * WorkSuite Sinhala AI Engine — release zone API (docs/sinhala-ai-engine/SRS.md).
 *
 *   GET    /api/engine/overview                       gates, states, counts, publisher key
 *   PUT    /api/engine/datasets/{id}/rights           provenance & rights (approve: true)    TRNPIPE 001
 *   POST   /api/engine/datasets/{id}/freeze           grouped splits + manifest hash         TRNPIPE 003
 *   POST   /api/engine/datasets/{id}/withdraw         withdraw from future training          TRNPIPE 006
 *   PUT    /api/engine/training/{id}/lineage          record post-run lineage                TRNPIPE 002
 *   GET    /api/engine/evaluations                    list reports
 *   POST   /api/engine/evaluations                    create report; gates computed          §17
 *   POST   /api/engine/evaluations/{id}/approve       role: release | language               §17.4
 *   GET    /api/engine/packages                       inventory                              ADM 001
 *   POST   /api/engine/packages                       register candidate                     ADM 002
 *   POST   /api/engine/packages/{id}/transition       {to, reason}                           §10.1
 *   POST   /api/engine/packages/rollback              {task}                                 ADM 004
 *   GET    /api/engine/packages/{id}/manifest         signed manifest + signature download   §14.5
 */
class EngineController extends BaseApiController
{
    private function db() { return \Config\Database::connect(); }

    private function guard(string $perm)
    {
        // Admins pass via can(); otherwise fall back to the existing models.* permission.
        if ($this->can($perm)) return null;
        return $this->requirePermission($perm === 'engine.view' ? 'models.view' : 'models.manage');
    }

    public function overview()
    {
        if ($r = $this->guard('engine.view')) return $r;
        $pkgs = new EnginePackageService();
        $counts = $this->db()->table('engine_packages')->select('state, COUNT(*) AS n')->groupBy('state')->get()->getResultArray();
        return $this->ok([
            'gates'          => EngineGateService::GATES,
            'states'         => EnginePackageService::STATES,
            'transitions'    => EnginePackageService::TRANSITIONS,
            'runtimes'       => EnginePackageService::RUNTIMES,
            'blocked_licenses' => EnginePackageService::BLOCKED_LICENSES,
            'package_counts' => array_column($counts, 'n', 'state'),
            'publisher_key'  => ['key_id' => $pkgs->keyId(), 'ed25519_public' => base64_encode($pkgs->publicKey())],
        ]);
    }

    // ------------------------------------------------------------ datasets

    public function datasetRights($id = null)
    {
        if ($r = $this->guard('engine.manage')) return $r;
        return $this->wrap(fn () => (new EngineDatasetService())->setRights((int) $id, $this->input()), 'Dataset rights updated.');
    }

    public function datasetFreeze($id = null)
    {
        if ($r = $this->guard('engine.manage')) return $r;
        $in = $this->input();
        return $this->wrap(fn () => (new EngineDatasetService())->freeze((int) $id, array_filter([
            'train' => $in['train'] ?? null, 'dev' => $in['dev'] ?? null,
        ], 'is_numeric') ?: ['train' => 0.8, 'dev' => 0.1]), 'Dataset frozen.');
    }

    public function datasetWithdraw($id = null)
    {
        if ($r = $this->guard('engine.manage')) return $r;
        return $this->wrap(fn () => (new EngineDatasetService())->withdraw((int) $id), 'Dataset withdrawn.');
    }

    public function trainingLineage($id = null)
    {
        if ($r = $this->guard('engine.manage')) return $r;
        $job = $this->db()->table('training_jobs')->where('id', (int) $id)->get()->getRowArray();
        if (! $job) return $this->failure('Job not found.', 404);
        $in  = $this->input();
        $upd = array_intersect_key($in, array_flip(['task', 'base_digest', 'code_commit', 'container_digest', 'hardware', 'output_digest']));
        foreach (['base_digest', 'output_digest'] as $h) {
            if (isset($upd[$h]) && ! preg_match('/^[a-f0-9]{64}$/', (string) $upd[$h])) {
                return $this->failure("{$h} must be a sha256 hex digest.", 422);
            }
        }
        if (! $upd) return $this->failure('Nothing to update.', 422);
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('training_jobs')->where('id', (int) $id)->update($upd);
        return $this->ok($this->db()->table('training_jobs')->where('id', (int) $id)->get()->getRowArray(), 'Lineage recorded.');
    }

    // ------------------------------------------------------------ evaluations

    public function evaluations()
    {
        if ($r = $this->guard('engine.view')) return $r;
        $rows = $this->db()->table('engine_evaluations')->orderBy('id', 'DESC')->limit(200)->get()->getResultArray();
        foreach ($rows as &$row) {
            foreach (['metrics', 'baseline_metrics', 'subgroups', 'gate_result'] as $j) $row[$j] = json_decode((string) $row[$j], true);
        }
        return $this->ok($rows);
    }

    public function createEvaluation()
    {
        if ($r = $this->guard('engine.manage')) return $r;
        $in   = $this->input();
        $task = (string) ($in['task'] ?? '');
        if (! in_array($task, EngineGateService::tasks(), true)) {
            return $this->failure('task must be one of: ' . implode(', ', EngineGateService::tasks()), 422);
        }
        foreach (['candidate_digest', 'suite_digest'] as $h) {
            if (! preg_match('/^[a-f0-9]{64}$/', (string) ($in[$h] ?? ''))) return $this->failure("{$h} must be a sha256 hex digest.", 422);
        }
        if (! is_array($in['metrics'] ?? null)) return $this->failure('metrics object is required.', 422);

        $baseline = is_array($in['baseline_metrics'] ?? null) ? $in['baseline_metrics'] : null;
        $subs     = is_array($in['subgroups'] ?? null) ? $in['subgroups'] : [];
        $gate     = (new EngineGateService())->evaluate($task, $in['metrics'], $baseline, $subs);

        $uuid = $this->uuid();
        $this->db()->table('engine_evaluations')->insert([
            'uuid'             => $uuid,
            'task'             => $task,
            'training_job_id'  => (int) ($in['training_job_id'] ?? 0) ?: null,
            'candidate_digest' => $in['candidate_digest'],
            'baseline_digest'  => $in['baseline_digest'] ?? null,
            'suite_digest'     => $in['suite_digest'],
            'hardware'         => $in['hardware'] ?? null,
            'runtime_lock'     => $in['runtime_lock'] ?? null,
            'metrics'          => json_encode($in['metrics']),
            'baseline_metrics' => $baseline ? json_encode($baseline) : null,
            'subgroups'        => $subs ? json_encode($subs) : null,
            'gate_result'      => json_encode($gate),
            'decision'         => $gate['pass'] ? 'pass' : 'fail',
            'notes'            => $in['notes'] ?? null,
            'created_by'       => $this->userId(),
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $row = $this->db()->table('engine_evaluations')->where('uuid', $uuid)->get()->getRowArray();
        $row['gate_result'] = $gate;
        return $this->ok($row, $gate['pass'] ? 'Evaluation passed its release gate.' : 'Evaluation did not pass its release gate.', 201);
    }

    public function approveEvaluation($id = null)
    {
        if ($r = $this->guard('engine.sign')) return $r;
        $ev = $this->db()->table('engine_evaluations')->where('id', (int) $id)->get()->getRowArray();
        if (! $ev) return $this->failure('Evaluation not found.', 404);
        if ($ev['decision'] !== 'pass') return $this->failure('Only passing evaluations can be approved.', 409);
        $role = (string) ($this->input()['role'] ?? '');
        $col  = ['release' => 'release_approver', 'language' => 'language_approver'][$role] ?? null;
        if (! $col) return $this->failure('role must be release or language.', 422);
        $other = $col === 'release_approver' ? 'language_approver' : 'release_approver';
        if ((int) $ev[$other] === $this->userId()) {
            return $this->failure('Release owner and language lead approvals must come from different people.', 409);
        }
        $this->db()->table('engine_evaluations')->where('id', (int) $id)->update([$col => $this->userId(), 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok($this->db()->table('engine_evaluations')->where('id', (int) $id)->get()->getRowArray(), 'Approval recorded.');
    }

    // ------------------------------------------------------------ packages

    public function packages()
    {
        if ($r = $this->guard('engine.view')) return $r;
        $rows = $this->db()->table('engine_packages p')
            ->select('p.id, p.package_id, p.version, p.task, p.languages, p.runtime_name, p.runtime_range, p.base_model, p.quantization,
                      p.license_id, p.rights_approval_id, p.state, p.manifest_sha256, p.publisher_key_id, p.min_ram_gib, p.min_disk_gib,
                      p.artifacts, p.state_history, p.updated_at, e.uuid AS evaluation_uuid, e.decision AS evaluation_decision')
            ->join('engine_evaluations e', 'e.id = p.evaluation_id', 'left')
            ->orderBy('p.id', 'DESC')->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['artifacts']     = json_decode((string) $row['artifacts'], true);
            $row['state_history'] = json_decode((string) $row['state_history'], true);
        }
        return $this->ok($rows);
    }

    public function createPackage()
    {
        if ($r = $this->guard('engine.manage')) return $r;
        $in  = $this->input();
        $svc = new EnginePackageService();

        $task    = (string) ($in['task'] ?? '');
        $runtime = (string) ($in['runtime_name'] ?? '');
        $version = (string) ($in['version'] ?? '');
        if (! in_array($task, EngineGateService::tasks(), true)) return $this->failure('Unknown task.', 422);
        if (! in_array($runtime, EnginePackageService::RUNTIMES, true)) return $this->failure('Unknown runtime.', 422);
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) return $this->failure('version must be semver (x.y.z).', 422);
        if (trim((string) ($in['runtime_range'] ?? '')) === '') return $this->failure('runtime_range must pin exact compatible runtime bounds.', 422);
        if (trim((string) ($in['license_id'] ?? '')) === '') return $this->failure('license_id is required.', 422);
        $artifacts = is_array($in['artifacts'] ?? null) ? array_values($in['artifacts']) : [];
        if ($errs = $svc->validateArtifacts($artifacts)) return $this->failure(implode(' ', $errs), 422);

        $evalId = null;
        if (! empty($in['evaluation_uuid'])) {
            $evalId = $this->db()->table('engine_evaluations')->where('uuid', $in['evaluation_uuid'])->get()->getRowArray()['id'] ?? null;
            if (! $evalId) return $this->failure('Evaluation not found.', 422);
        }
        $jobId = (int) ($in['training_job_id'] ?? 0) ?: null;
        if ($jobId) {
            $job = $this->db()->table('training_jobs')->where('id', $jobId)->get()->getRowArray();
            if (! $job) return $this->failure('Training job not found.', 422);
            if (empty($job['dataset_manifest_hash'])) {
                return $this->failure('Training run has no frozen dataset lineage; it cannot produce an engine package.', 409);
            }
        }

        $packageId = $this->uuid();
        $this->db()->table('engine_packages')->insert([
            'package_id'         => $packageId,
            'version'            => $version,
            'task'               => $task,
            'languages'          => implode(',', array_map('strval', (array) ($in['languages'] ?? ['si']))),
            'runtime_name'       => $runtime,
            'runtime_range'      => $in['runtime_range'],
            'base_model'         => $in['base_model'] ?? null,
            'quantization'       => $in['quantization'] ?? null,
            'artifacts'          => json_encode(array_map(fn ($a) => [
                'path' => (string) $a['path'], 'sha256' => (string) $a['sha256'], 'size_bytes' => (int) $a['size_bytes'],
            ], $artifacts)),
            'license_id'         => $in['license_id'],
            'rights_approval_id' => $in['rights_approval_id'] ?? null,
            'evaluation_id'      => $evalId,
            'training_job_id'    => $jobId,
            'min_ram_gib'        => is_numeric($in['min_ram_gib'] ?? null) ? $in['min_ram_gib'] : null,
            'min_disk_gib'       => is_numeric($in['min_disk_gib'] ?? null) ? $in['min_disk_gib'] : null,
            'state'              => 'candidate',
            'state_history'      => json_encode([['from' => null, 'to' => 'candidate', 'actor' => $this->userId(), 'at' => gmdate('c')]]),
            'created_by'         => $this->userId(),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
        return $this->ok($this->db()->table('engine_packages')->where('package_id', $packageId)->get()->getRowArray(), 'Package registered as candidate.', 201);
    }

    public function transition($id = null)
    {
        $in = $this->input();
        $to = (string) ($in['to'] ?? '');
        // Signing (→staged), activation and revocation need the signing permission.
        if ($r = $this->guard(in_array($to, ['staged', 'active', 'revoked'], true) ? 'engine.sign' : 'engine.manage')) return $r;
        $pkg = $this->db()->table('engine_packages')->where('id', (int) $id)->get()->getRowArray();
        if (! $pkg) return $this->failure('Package not found.', 404);
        if (! in_array($to, EnginePackageService::STATES, true)) return $this->failure('Unknown state.', 422);
        if ($to === 'revoked' && trim((string) ($in['reason'] ?? '')) === '') return $this->failure('A reason is required to revoke.', 422);
        return $this->wrap(fn () => (new EnginePackageService())->transition($pkg, $to, $this->userId(), $in['reason'] ?? null), "Package moved to {$to}.");
    }

    public function rollback()
    {
        if ($r = $this->guard('engine.sign')) return $r;
        $task = (string) ($this->input()['task'] ?? '');
        return $this->wrap(fn () => (new EnginePackageService())->rollback($task, $this->userId()), 'Rolled back to the previous approved package.');
    }

    public function manifest($id = null)
    {
        if ($r = $this->guard('engine.view')) return $r;
        $pkg = $this->db()->table('engine_packages')->where('id', (int) $id)->get()->getRowArray();
        if (! $pkg || empty($pkg['manifest'])) return $this->failure('Package is not signed yet.', 404);
        $svc = new EnginePackageService();
        return $this->ok([
            'manifest'         => json_decode($pkg['manifest'], true),
            'manifest_bytes'   => base64_encode($pkg['manifest']), // exact signed bytes
            'manifest_sha256'  => $pkg['manifest_sha256'],
            'signature'        => $pkg['signature'],
            'algorithm'        => 'Ed25519',
            'publisher_key_id' => $pkg['publisher_key_id'],
            'verified'         => $svc->verify($pkg),
        ]);
    }

    // ------------------------------------------------------------ helpers

    private function wrap(callable $fn, string $msg)
    {
        try {
            return $this->ok($fn(), $msg);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 409);
        }
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
