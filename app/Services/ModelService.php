<?php

namespace App\Services;

use App\Models\ModelModel;
use App\Models\ServerModel;

/**
 * ModelService — reconciles the `models` table with what is actually installed
 * on each node (via OllamaService::tags()), and brokers pull/delete/load/unload.
 */
class ModelService
{
    protected ModelModel $models;
    protected ServerModel $servers;

    public function __construct()
    {
        $this->models  = new ModelModel();
        $this->servers = new ServerModel();
    }

    /**
     * Pull the node's installed model list and upsert it into `models`,
     * removing rows for models no longer present on that node.
     * Returns ['synced' => int, 'removed' => int].
     *
     * @throws \RuntimeException when the node is unreachable
     */
    public function syncServer(array $server): array
    {
        $provider = new ProviderService();
        $now      = date('Y-m-d H:i:s');
        $seen     = [];

        // External providers (OpenAI/Anthropic/Gemini) expose a flat model-id list;
        // self-hosted Ollama nodes report rich per-model metadata via /api/tags.
        if ($provider->isExternal($server['kind'] ?? 'ollama')) {
            $kind = $server['kind'];
            foreach ($provider->listModels($server) as $name) {
                if (! $name) {
                    continue;
                }
                $seen[] = $name;
                $row = ['server_id' => $server['id'], 'name' => $name, 'family' => $kind, 'status' => 'available', 'synced_at' => $now];
                $existing = $this->models->where('server_id', $server['id'])->where('name', $name)->first();
                $existing ? $this->models->update($existing['id'], $row) : $this->models->insert($row);
            }
        } else {
            $tags = (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->tags();
            $list = $tags['models'] ?? [];

            foreach ($list as $m) {
                $name = $m['name'] ?? ($m['model'] ?? null);
                if (! $name) {
                    continue;
                }
                $seen[] = $name;
                $details = $m['details'] ?? [];
                $row = [
                    'server_id'      => $server['id'],
                    'name'           => $name,
                    'family'         => $details['family'] ?? null,
                    'parameter_size' => $details['parameter_size'] ?? null,
                    'quantization'   => $details['quantization_level'] ?? null,
                    'format'         => $details['format'] ?? null,
                    'size_bytes'     => isset($m['size']) ? (int) $m['size'] : null,
                    'digest'         => isset($m['digest']) ? substr((string) $m['digest'], 0, 80) : null,
                    'modified_at'    => $this->toDateTime($m['modified_at'] ?? null),
                    'status'         => 'available',
                    'synced_at'      => $now,
                ];
                $existing = $this->models->where('server_id', $server['id'])->where('name', $name)->first();
                if ($existing) {
                    $this->models->update($existing['id'], $row);
                } else {
                    $this->models->insert($row);
                }
            }
        }

        // Drop models that vanished from the node.
        $removed = 0;
        $current = $this->models->where('server_id', $server['id'])->findAll();
        foreach ($current as $row) {
            if (! in_array($row['name'], $seen, true)) {
                $this->models->delete($row['id']);
                $removed++;
            }
        }

        return ['synced' => count($seen), 'removed' => $removed];
    }

    /** Sync every non-deleted server; collects per-server results/errors. */
    public function syncAll(): array
    {
        $out = [];
        foreach ($this->servers->findAll() as $server) {
            try {
                $out[] = ['server_id' => $server['id'], 'name' => $server['name']] + $this->syncServer($server);
            } catch (\Throwable $e) {
                $out[] = ['server_id' => $server['id'], 'name' => $server['name'], 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    public function clientFor(array $server): OllamaService
    {
        return new OllamaService($server['api_url'], $server['auth_token'] ?? null);
    }

    private function toDateTime(?string $iso): ?string
    {
        if (! $iso) {
            return null;
        }
        try {
            return (new \DateTime($iso))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
