<?php

namespace App\Commands;

use App\Services\MlModelService;
use CodeIgniter\CLI\BaseCommand;

/** `spark ml:train-table <model id> [task]` — the detached trainer behind a new table model (started by the API). */
class MlTrainTable extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'ml:train-table';
    protected $description = 'Train a table model (classic ML on its uploaded CSV) and register the result.';
    protected $usage       = 'ml:train-table <model id> [auto|classification|regression]';

    public function run(array $params)
    {
        (new MlModelService())->trainTable((int) ($params[0] ?? 0), (string) ($params[1] ?? 'auto'));
        return EXIT_SUCCESS;
    }
}
