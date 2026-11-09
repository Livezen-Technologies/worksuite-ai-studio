<?php

namespace App\Models;

use CodeIgniter\Model;

class ServerModel extends Model
{
    protected $table          = 'servers';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'name', 'description', 'kind', 'hostname', 'ip_address', 'api_url', 'port', 'auth_token',
        'gpu_model', 'cuda_version', 'cpu_cores', 'ram_gb', 'storage_gb', 'os',
        'ollama_version', 'is_local', 'status', 'health_score', 'last_heartbeat_at',
        'tunnel_port', 'ssh_public_key', 'ssh_fingerprint', 'enrolled_at',
        'is_enabled', 'disabled_at', 'disabled_reason',
        'deployment', 'accelerator', 'rocm_version', 'cloud_provider', 'region', 'container_image', 'max_concurrency',
        'gpu_util_pct', 'vram_used_gb', 'cpu_util_pct', 'ram_used_gb', 'disk_used_gb', 'gpu_temp_c', 'metrics_at', 'gpu_vram_gb',
    ];

    protected $validationRules = [
        'name'    => 'required|min_length[2]|max_length[120]',
        'api_url' => 'required|max_length[255]',
    ];

    /** Never leak the node auth token to the API surface. */
    public function publicRow(array $row): array
    {
        $row['has_token']  = ! empty($row['auth_token'] ?? null);
        $row['cuda_cores'] = \App\Libraries\GpuSpecs::forServer($row); // derived from the GPU model; nvidia-smi does not report it
        $row['availability']  = \App\Services\NodeTelemetryService::availability($row);
        $row['metrics_fresh'] = ! empty($row['metrics_at']) && strtotime($row['metrics_at']) >= time() - \App\Services\NodeTelemetryService::STALE_SECONDS;
        $row['self_hosted']   = ! (new \App\Services\ProviderService())->isExternal((string) ($row['kind'] ?? 'ollama'));
        $row['is_enabled'] = (int) ($row['is_enabled'] ?? 1) === 1;
        unset($row['auth_token'], $row['deleted_at'], $row['ssh_public_key'], $row['agent_token_hash']);
        return $row;
    }
}
