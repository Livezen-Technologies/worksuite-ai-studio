<?php

namespace App\Models;

use CodeIgniter\Model;

class DatasetItemModel extends Model
{
    protected $table          = 'dataset_items';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = false;
    protected $allowedFields  = ['dataset_id', 'instruction', 'input', 'output', 'tokens', 'hash', 'created_at'];
}
