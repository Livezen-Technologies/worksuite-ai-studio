<?php

namespace App\Models;

use CodeIgniter\Model;

class TrainingJobModel extends Model
{
    protected $table         = 'training_jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'dataset_id', 'base_model', 'method', 'hyperparams', 'status', 'notes', 'logs', 'created_by',
        // Sinhala AI Engine lineage (FR TRNPIPE 002)
        'task', 'dataset_manifest_hash', 'dataset_split_hash', 'base_digest', 'config_hash', 'code_commit',
        'container_digest', 'seed', 'hardware', 'output_digest',
        'placement_status',
        // Automated pipelines (TrainingPipelineService)
        'pipeline_id', 'recipe', 'trigger_reason', 'attempt', 'max_attempts', 'error', 'claimed_at', 'cancel_requested',
        'output_status', 'output_model', 'output_path',
        // Live progress (TrainingProgressController)
        'server_id', 'progress_pct', 'phase', 'current_step', 'total_steps', 'epoch', 'eta_seconds', 'live',
        'started_at', 'finished_at', 'last_report_at', 'report_token_hash',
    ];

    /** Jobs joined with their dataset name/count. */
    public function listWithDataset(): array
    {
        return $this->db->table('training_jobs t')
            ->select('t.*, d.name AS dataset_name, d.item_count AS dataset_items')
            ->join('datasets d', 'd.id = t.dataset_id', 'left')
            ->orderBy('t.id', 'DESC')
            ->get()->getResultArray();
    }
}
