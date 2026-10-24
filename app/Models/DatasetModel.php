<?php

namespace App\Models;

use CodeIgniter\Model;

class DatasetModel extends Model
{
    protected $table         = 'datasets';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'description', 'language', 'item_count', 'token_estimate', 'created_by',
        'task', 'source_ref', 'permitted_use', 'rights_ref', 'consent_required', 'consent_ref', 'rights_status',
    ];
}
