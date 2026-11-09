<?php

namespace App\Controllers\Api;

use App\Models\ApiKeyModel;
use App\Models\ApiLogModel;
use App\Services\ApiTokenService;

/**
 * API Keys management (dashboard side).
 *
 *   GET    /api/apikeys                 list (own keys; all keys with apikeys.admin)
 *   POST   /api/apikeys                 create — the secret is returned ONCE   (apikeys.manage)
 *   PUT    /api/apikeys/{id}            rename / update settings               (apikeys.manage)
 *   POST   /api/apikeys/{id}/rotate     new secret (returned once)             (apikeys.manage)
 *   POST   /api/apikeys/{id}/revoke     revoke immediately, permanently        (apikeys.manage)
 *   POST   /api/apikeys/{id}/tokens     short-lived JWT access token           (apikeys.tokens)
 *   GET    /api/apikeys/{id}/usage      30-day usage + recent request history  (apikeys.view)
 *   DELETE /api/apikeys/{id}            delete                                 (apikeys.manage)
 *
 * Users manage their own personal / application keys; organization and service-account keys,
 * and other people's keys, need apikeys.admin.
 */
class ApiKeysController extends BaseApiController
{
    /** Gateway scopes a key can carry. download_* open /api/v1/catalog and the file / dataset downloads. */
    public const SCOPES = ['chat', 'generate', 'embeddings', 'models', 'transcribe', 'download_models', 'download_datasets', 'classify'];

    public const TYPES       = ['personal', 'application', 'organization', 'service_account'];
    public const ADMIN_TYPES = ['organization', 'service_account'];

    private function keys(): ApiKeyModel { return new ApiKeyModel(); }

    private function isKeyAdmin(): bool { return $this->can('apikeys.admin'); }

    /** The key, if it exists and the caller may act on it. */
    private function owned($id): ?array
    {
        $row = $this->keys()->find((int) $id);
        if (! $row) return null;
        if ($this->isKeyAdmin()) return $row;
        $mine = (int) ($row['user_id'] ?? 0) === $this->userId();
        return $mine && ! in_array($row['key_type'] ?? 'personal', self::ADMIN_TYPES, true) ? $row : null;
    }

    public function index()
    {
        if ($r = $this->requirePermission('apikeys.view')) return $r;
        $m = $this->keys()->orderBy('id', 'DESC');
        if (! $this->isKeyAdmin()) $m->where('user_id', $this->userId());
        $type = (string) ($this->request->getGet('type') ?? '');
        if (in_array($type, self::TYPES, true)) $m->where('key_type', $type);
        $rows = array_map([$this->keys(), 'publicRow'], $m->findAll());

        $logs  = new ApiLogModel();
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        foreach ($rows as &$row) {
            $row['tokens_30d']   = $logs->tokensSince((int) $row['id'], $since);
            $row['requests_30d'] = $logs->where('api_key_id', (int) $row['id'])->where('created_at >=', $since)->countAllResults();
        }
        return $this->ok($rows);
    }

    public function create()
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, ['name' => 'required|min_length[2]|max_length[120]'])) {
            return $this->failure('A key name is required.', 422, $errors);
        }
        $type = (string) ($in['key_type'] ?? 'personal');
        if (! in_array($type, self::TYPES, true)) {
            return $this->failure('Unknown key type.', 422);
        }
        if (in_array($type, self::ADMIN_TYPES, true) && ! $this->isKeyAdmin()) {
            return $this->failure('Only API key administrators can create organization or service-account keys.', 403);
        }
        if ($type === 'application' && trim((string) ($in['application'] ?? '')) === '') {
            return $this->failure('An application name is required for application keys.', 422);
        }

        $m    = $this->keys();
        $cred = $m->mint();
        $id   = $m->insert($this->fields($in, [
            'name'          => $in['name'],
            'key_type'      => $type,
            'user_id'       => $type === 'service_account' ? null : $this->userId(),
            'api_key'       => $cred['key'],
            'secret_hash'   => $cred['hash'],
            'secret_prefix' => $cred['prefix'],
            'rotated_at'    => date('Y-m-d H:i:s'),
        ]), true);

        $row = $m->publicRow($m->find($id));
        $row['secret'] = $cred['secret']; // shown exactly once; only the hash is stored
        return $this->ok($row, 'API key created. Copy the secret now — it won’t be shown again.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        if (! ($row = $this->owned($id))) return $this->failure('API key not found.', 404);
        if (ApiKeyModel::state($row) === 'revoked') return $this->failure('A revoked key cannot be changed.', 409);
        $in = $this->input();
        if (isset($in['status']) && ! in_array($in['status'], ['active', 'disabled'], true)) {
            return $this->failure('Status must be active or disabled; use revoke to revoke a key.', 422);
        }
        $m = $this->keys();
        $m->update((int) $id, $this->fields($in));
        return $this->ok($m->publicRow($m->find((int) $id)), 'API key updated.');
    }

    public function rotate($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        if (! ($row = $this->owned($id))) return $this->failure('API key not found.', 404);
        if (ApiKeyModel::state($row) === 'revoked') return $this->failure('A revoked key cannot be rotated.', 409);
        $m    = $this->keys();
        $cred = $m->mint();
        $m->update((int) $id, [
            'secret_hash' => $cred['hash'], 'secret_prefix' => $cred['prefix'], 'rotated_at' => date('Y-m-d H:i:s'),
            'token_version' => (int) ($row['token_version'] ?? 0) + 1, // old access tokens stop working
        ]);
        $out = $m->publicRow($m->find((int) $id));
        $out['secret'] = $cred['secret'];
        return $this->ok($out, 'Secret rotated. Copy it now — it won’t be shown again.');
    }

    public function revoke($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        if (! ($row = $this->owned($id))) return $this->failure('API key not found.', 404);
        $m = $this->keys();
        $m->update((int) $id, [
            'status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s'),
            'token_version' => (int) ($row['token_version'] ?? 0) + 1,
        ]);
        return $this->ok($m->publicRow($m->find((int) $id)), 'API key revoked. It and its access tokens stop working immediately.');
    }

    public function tokens($id = null)
    {
        if ($r = $this->requirePermission('apikeys.tokens')) return $r;
        if (! ($row = $this->owned($id))) return $this->failure('API key not found.', 404);
        if (ApiKeyModel::state($row) !== 'active') return $this->failure('Tokens can only be issued for an active key.', 409);
        $in     = $this->input();
        $scopes = is_array($in['scopes'] ?? null) ? $in['scopes'] : null;
        $ttl    = isset($in['expires_in']) ? (int) $in['expires_in'] : null;
        if (! empty($in['expires_at']) && ($t = strtotime((string) $in['expires_at']))) $ttl = $t - time();
        if ($ttl !== null && $ttl < 60) return $this->failure('The token must be valid for at least a minute.', 422);
        return $this->ok((new ApiTokenService())->issue($row, $scopes, $ttl), 'Access token issued. Copy it now — it won’t be shown again.', 201);
    }

    public function usage($id = null)
    {
        if ($r = $this->requirePermission('apikeys.view')) return $r;
        if (! $this->owned($id)) return $this->failure('API key not found.', 404);
        $db    = \Config\Database::connect();
        $since = date('Y-m-d 00:00:00', strtotime('-29 days'));
        $daily = $db->table('api_logs')
            ->select('DATE(created_at) AS day, COUNT(*) AS requests, COALESCE(SUM(total_tokens),0) AS tokens, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors')
            ->where('api_key_id', (int) $id)->where('created_at >=', $since)
            ->groupBy('DATE(created_at)')->orderBy('day', 'ASC')->get()->getResultArray();
        $models = $db->table('api_logs')
            ->select('model, COUNT(*) AS requests, COALESCE(SUM(total_tokens),0) AS tokens')
            ->where('api_key_id', (int) $id)->where('created_at >=', $since)->where('model IS NOT NULL')
            ->groupBy('model')->orderBy('tokens', 'DESC')->get()->getResultArray();
        $recent = $db->table('api_logs')
            ->select('id, endpoint, model, status, prompt_tokens, completion_tokens, total_tokens, processing_ms, client_ip, error, created_at')
            ->where('api_key_id', (int) $id)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $cast = static fn (array $rows) => array_map(static fn ($r) => array_map(static fn ($v) => is_numeric($v) ? $v + 0 : $v, $r), $rows);
        return $this->ok([
            'daily'    => $cast($daily),
            'models'   => $cast($models),
            'recent'   => $cast($recent),
            'totals'   => [
                'requests' => array_sum(array_column($daily, 'requests')),
                'tokens'   => array_sum(array_column($daily, 'tokens')),
                'errors'   => array_sum(array_column($daily, 'errors')),
            ],
        ]);
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        if (! $this->owned($id)) return $this->failure('API key not found.', 404);
        $this->keys()->delete((int) $id);
        return $this->ok(null, 'API key deleted.');
    }

    /** Whitelist editable fields; JSON-encode array fields. */
    private function fields(array $in, array $base = []): array
    {
        $out = $base;
        foreach (['name', 'scopes', 'rate_limit_per_min', 'daily_token_limit', 'monthly_token_limit', 'status', 'expires_at',
                  'application', 'organization', 'description', 'rotation_days'] as $f) {
            if (array_key_exists($f, $in) && ! array_key_exists($f, $base)) {
                $out[$f] = $in[$f] === '' ? null : $in[$f];
            }
        }
        if (is_array($out['scopes'] ?? null)) { // only known scopes are stored
            $out['scopes'] = implode(',', array_values(array_intersect($out['scopes'], self::SCOPES)));
        }
        foreach (['allowed_models', 'allowed_ips'] as $f) {
            if (array_key_exists($f, $in)) {
                $val = $in[$f];
                $out[$f] = (is_array($val) && $val) ? json_encode(array_values($val)) : null;
            }
        }
        return $out;
    }
}
