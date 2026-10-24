<?php

namespace App\Services;

use RuntimeException;

/**
 * Dataset governance for engine training (SRS FR TRNPIPE 001–003, §16.2).
 *
 * - Datasets start quarantined; only an approved rights record un-quarantines them.
 * - Freezing assigns train/dev/test splits by group (speaker / document / session),
 *   never by row, so a group can't leak across partitions, then records an
 *   immutable manifest hash and split hash and bumps the dataset version.
 * - Withdrawing removes the dataset from future training; already trained runs
 *   keep their lineage and are flagged, without claiming automatic unlearning.
 */
class EngineDatasetService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function setRights(int $id, array $in): array
    {
        $ds = $this->get($id);
        $upd = array_intersect_key($in, array_flip(['task', 'source_ref', 'permitted_use', 'rights_ref', 'consent_required', 'consent_ref']));
        if (isset($upd['task']) && ! in_array($upd['task'], ['text', 'asr', 'tts', 'translation', 'transliteration', 'spelling', 'resource'], true)) {
            unset($upd['task']);
        }
        if (isset($upd['consent_required'])) $upd['consent_required'] = $upd['consent_required'] ? 1 : 0;

        $merged = array_merge($ds, $upd);
        if (($in['approve'] ?? false)) {
            if (empty($merged['rights_ref']) || empty($merged['permitted_use']) || empty($merged['source_ref'])) {
                throw new RuntimeException('Source, permitted use and rights reference are required to approve a dataset.');
            }
            if ($merged['consent_required'] && empty($merged['consent_ref'])) {
                throw new RuntimeException('This dataset requires a consent reference before approval.');
            }
            $upd['rights_status'] = 'approved';
        }
        if ($upd) {
            $upd['updated_at'] = date('Y-m-d H:i:s');
            $this->db->table('datasets')->where('id', $id)->update($upd);
        }
        return $this->get($id);
    }

    /**
     * Freeze: deterministic grouped split + manifest hash.
     * Ratios default 80/10/10. Rows without group_key are grouped by their own hash.
     */
    public function freeze(int $id, array $ratios = ['train' => 0.8, 'dev' => 0.1]): array
    {
        $ds = $this->get($id);
        if ($ds['rights_status'] !== 'approved') {
            throw new RuntimeException('Only rights-approved datasets can be frozen (items without approved rights stay quarantined).');
        }
        $train = (float) ($ratios['train'] ?? 0.8);
        $dev   = (float) ($ratios['dev'] ?? 0.1);
        if ($train <= 0 || $dev < 0 || $train + $dev >= 1) throw new RuntimeException('Invalid split ratios.');

        $rows = $this->db->table('dataset_items')->select('id, hash, group_key')->where('dataset_id', $id)->orderBy('id')->get()->getResultArray();
        if (! $rows) throw new RuntimeException('Dataset is empty.');

        $assign = ['train' => [], 'dev' => [], 'test' => []];
        $manifest = hash_init('sha256');
        $splitCtx = hash_init('sha256');
        foreach ($rows as $r) {
            $group  = $r['group_key'] !== null && $r['group_key'] !== '' ? $r['group_key'] : $r['hash'];
            // Stable bucket in [0,1) from the group, salted with the dataset id.
            $bucket = hexdec(substr(hash('sha256', $id . '|' . $group), 0, 8)) / 0x100000000;
            $split  = $bucket < $train ? 'train' : ($bucket < $train + $dev ? 'dev' : 'test');
            $assign[$split][] = (int) $r['id'];
            hash_update($manifest, $r['hash'] . "\n");
            hash_update($splitCtx, $r['hash'] . ':' . $split . "\n");
        }

        $this->db->transStart();
        foreach ($assign as $split => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $this->db->table('dataset_items')->whereIn('id', $chunk)->update(['split' => $split]);
            }
        }
        $this->db->table('datasets')->where('id', $id)->update([
            'manifest_hash' => hash_final($manifest),
            'split_hash'    => hash_final($splitCtx),
            'version'       => (int) $ds['version'] + 1,
            'frozen_at'     => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->db->transComplete();

        $out = $this->get($id);
        $out['split_counts'] = array_map('count', $assign);
        return $out;
    }

    /** FR TRNPIPE 006 — withdraw from future training; report affected runs. */
    public function withdraw(int $id): array
    {
        $this->get($id);
        $this->db->table('datasets')->where('id', $id)->update(['rights_status' => 'withdrawn', 'updated_at' => date('Y-m-d H:i:s')]);
        $runs = $this->db->table('training_jobs')->select('id, name, status')->where('dataset_id', $id)->get()->getResultArray();
        return [
            'dataset'       => $this->get($id),
            'affected_runs' => $runs,
            'note'          => 'Withdrawn data is excluded from future runs. Existing trained models are not automatically unlearned; review affected runs.',
        ];
    }

    /** Guard used before a training kit is generated. */
    public function assertTrainable(?int $id): array
    {
        if (! $id) throw new RuntimeException('A dataset is required.');
        $ds = $this->get($id);
        if ($ds['rights_status'] !== 'approved') throw new RuntimeException('Dataset rights are not approved (status: ' . $ds['rights_status'] . ').');
        if (empty($ds['manifest_hash'])) throw new RuntimeException('Freeze the dataset (immutable manifest + splits) before training.');
        return $ds;
    }

    private function get(int $id): array
    {
        $ds = $this->db->table('datasets')->where('id', $id)->get()->getRowArray();
        if (! $ds) throw new RuntimeException('Dataset not found.');
        return $ds;
    }
}
