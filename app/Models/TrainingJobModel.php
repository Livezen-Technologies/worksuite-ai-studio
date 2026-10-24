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
