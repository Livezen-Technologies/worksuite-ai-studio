<?php

namespace App\Models;

use CodeIgniter\Model;

class ModelConfigModel extends Model
{
    protected $table         = 'model_configs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'temperature', 'top_p', 'top_k', 'repeat_penalty', 'num_ctx', 'num_predict',
        'stop', 'seed', 'num_batch', 'num_thread', 'num_gpu', 'keep_alive', 'system_prompt',
    ];

    /** Defaults applied to a model that has no saved config yet. */
    public const DEFAULTS = [
        'temperature' => 0.80, 'top_p' => 0.90, 'top_k' => 40, 'repeat_penalty' => 1.10,
        'num_ctx' => 4096, 'num_predict' => -1, 'stop' => null, 'seed' => null,
        'num_batch' => null, 'num_thread' => null, 'num_gpu' => null,
        'keep_alive' => '5m', 'system_prompt' => null,
    ];

    public function forModel(string $name): array
    {
        $row = $this->where('name', $name)->first();
        if (! $row) {
            return array_merge(['name' => $name], self::DEFAULTS, ['exists' => false]);
        }
        $row['stop']   = $row['stop'] ? json_decode($row['stop'], true) : null;
        $row['exists'] = true;
        return $row;
    }

    /** Upsert a model's config; returns the stored row. */
    public function save_(string $name, array $params): array
    {
        $clean = array_intersect_key($params, array_flip($this->allowedFields));
        $clean['name'] = $name;
        if (isset($clean['stop']) && is_array($clean['stop'])) {
            $clean['stop'] = json_encode(array_values(array_filter($clean['stop'], 'strlen')));
        }
        $existing = $this->where('name', $name)->first();
        if ($existing) {
            $this->update($existing['id'], $clean);
        } else {
            $this->insert($clean);
        }
        return $this->forModel($name);
    }

    /**
     * Build the Ollama `options` payload (+ system) from a model's saved config,
     * used by the chat/generate proxy in Phase 4.
     */
    public function ollamaOptions(string $name): array
    {
        $c = $this->forModel($name);
        $opt = array_filter([
            'temperature'    => (float) $c['temperature'],
            'top_p'          => (float) $c['top_p'],
            'top_k'          => (int) $c['top_k'],
            'repeat_penalty' => (float) $c['repeat_penalty'],
            'num_ctx'        => (int) $c['num_ctx'],
            'num_predict'    => (int) $c['num_predict'],
            'seed'           => $c['seed'] !== null ? (int) $c['seed'] : null,
            'num_batch'      => $c['num_batch'] !== null ? (int) $c['num_batch'] : null,
            'num_thread'     => $c['num_thread'] !== null ? (int) $c['num_thread'] : null,
            'num_gpu'        => $c['num_gpu'] !== null ? (int) $c['num_gpu'] : null,
            'stop'           => $c['stop'] ?: null,
        ], static fn ($v) => $v !== null);

        return [
            'options'    => $opt,
            'system'     => $c['system_prompt'] ?: null,
            'keep_alive' => $c['keep_alive'] ?: '5m',
        ];
    }
}
