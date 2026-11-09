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
    public const SCOPES = ['chat', 'generate', 'embeddings', 'models', 'transcribe', 'tts', 'download_models', 'download_datasets', 'classify'];

    /** Scopes a user may give their own keys. Download scopes expose datasets and model files: admins only. */
    public const SELF_SCOPES = ['chat', 'generate', 'embeddings', 'models', 'transcribe', 'tts', 'classify'];
    /** Highest per-minute rate a non-admin may set on their own key. */
    public const SELF_MAX_RATE = 600;
    public const DEFAULT_RATE  = 60;

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

        if ($err = $this->policyError($in, null)) return $err;
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
        if ($err = $this->policyError($in, $row)) return $err;
        $m   = $this->keys();
        $upd = $this->fields($in);
        if ($this->isKeyAdmin()) {
            // An admin's change to someone else's key locks it, so the owner cannot undo the restriction.
            if (array_key_exists('admin_locked', $in)) $upd['admin_locked'] = ! empty($in['admin_locked']) ? 1 : 0;
            elseif ((int) ($row['user_id'] ?? 0) !== $this->userId()) $upd['admin_locked'] = 1;
        }
        // Disabling ends every access token issued so far; re-enabling must not bring them back.
        if (($upd['status'] ?? null) === 'disabled' && ($row['status'] ?? '') !== 'disabled') {
            $upd['token_version'] = (int) ($row['token_version'] ?? 0) + 1;
        }
        $m->update((int) $id, $upd);
        return $this->ok($m->publicRow($m->find((int) $id)), 'API key updated.');
    }

    public function rotate($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        if (! ($row = $this->owned($id))) return $this->failure('API key not found.', 404);
        if (ApiKeyModel::state($row) === 'revoked') return $this->failure('A revoked key cannot be rotated.', 409);
        // grace_hours (0-168, default 24): the old secret keeps working that long, so apps can switch over
        // without downtime. 0 ends it at once (use that when the secret has leaked).
        $grace = $this->input()['grace_hours'] ?? 24;
        $grace = is_numeric($grace) ? max(0, min(168, (int) $grace)) : 24;
        $m    = $this->keys();
        $cred = $m->mint();
        $m->update((int) $id, [
            'secret_hash' => $cred['hash'], 'secret_prefix' => $cred['prefix'], 'rotated_at' => date('Y-m-d H:i:s'),
            'previous_secret_hash' => $grace > 0 ? $row['secret_hash'] : null,
            'previous_secret_expires_at' => $grace > 0 ? date('Y-m-d H:i:s', time() + $grace * 3600) : null,
            'rotation_notified_at' => null,
            'token_version' => (int) ($row['token_version'] ?? 0) + 1, // old access tokens stop working
        ]);
        $out = $m->publicRow($m->find((int) $id));
        $out['secret'] = $cred['secret'];
        return $this->ok($out, $grace > 0
            ? "Secret rotated. The old secret keeps working for {$grace} hour" . ($grace > 1 ? 's' : '') . '. Copy the new one now — it won’t be shown again.'
            : 'Secret rotated; the old secret stopped working. Copy the new one now — it won’t be shown again.');
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

    /**
     * What a non-admin may change. Returns a 403/422 response, or null when allowed.
     * - scopes: only SELF_SCOPES (on a locked key: unchanged);
     * - rate limit: at most SELF_MAX_RATE;
     * - a key an admin has locked: only name, description and application, or disabling it.
     */
    private function policyError(array $in, ?array $row)
    {
        if (array_key_exists('scopes', $in) && ! is_array($in['scopes'])) return $this->failure('scopes must be a list.', 422, ['scopes' => 'Must be a list.']);
        if (array_key_exists('rate_limit_per_min', $in) && $in['rate_limit_per_min'] !== '' && $in['rate_limit_per_min'] !== null
            && (! is_numeric($in['rate_limit_per_min']) || (int) $in['rate_limit_per_min'] < 1 || (int) $in['rate_limit_per_min'] > 100000)) {
            return $this->failure('Requests per minute must be a number from 1 to 100000.', 422, ['rate_limit_per_min' => 'From 1 to 100000.']);
        }
        if ($this->isKeyAdmin()) return null;
        if (array_key_exists('admin_locked', $in)) return $this->failure('Only API key administrators can lock or unlock keys.', 403);
        if ($row && (int) ($row['admin_locked'] ?? 0) === 1) {
            $allowed = ['name', 'description', 'application'];
            if (array_key_exists('rotation_enforce', $in) || array_key_exists('rotation_days', $in)) {
                return $this->failure('An administrator manages this key’s rotation policy.', 403);
            }
            foreach (array_keys($in) as $f) {
                if (in_array($f, $allowed, true) || ($f === 'status' && $in[$f] === 'disabled') || ($f === 'status' && $in[$f] === ($row['status'] ?? null))) continue;
                if ($f === 'key_type') continue;
                return $this->failure('An administrator manages this key’s limits and access. You can rename or disable it; ask an administrator for other changes.', 403);
            }
            return null;
        }
        if (is_array($in['scopes'] ?? null) && ($extra = array_diff(array_intersect($in['scopes'], self::SCOPES), self::SELF_SCOPES))) {
            return $this->failure('Only an API key administrator can grant: ' . implode(', ', $extra) . '.', 403, ['scopes' => 'Needs an administrator.']);
        }
        if (isset($in['rate_limit_per_min']) && is_numeric($in['rate_limit_per_min']) && (int) $in['rate_limit_per_min'] > self::SELF_MAX_RATE) {
            return $this->failure('You can set up to ' . self::SELF_MAX_RATE . ' requests per minute; ask an administrator for more.', 403, ['rate_limit_per_min' => 'At most ' . self::SELF_MAX_RATE . '.']);
        }
        return null;
    }

    /** Whitelist editable fields; JSON-encode array fields. */
    private function fields(array $in, array $base = []): array
    {
        $out = $base;
        foreach (['name', 'scopes', 'rate_limit_per_min', 'daily_token_limit', 'monthly_token_limit', 'status', 'expires_at',
                  'application', 'organization', 'description', 'rotation_days', 'rotation_enforce', 'max_output_tokens'] as $f) {
            if (array_key_exists($f, $in) && ! array_key_exists($f, $base)) {
                $out[$f] = $in[$f] === '' ? null : $in[$f];
            }
        }
        if (array_key_exists('max_output_tokens', $out)) $out['max_output_tokens'] = $out['max_output_tokens'] === null ? null : max(1, min(1000000, (int) $out['max_output_tokens']));
        if (array_key_exists('rotation_enforce', $out)) $out['rotation_enforce'] = ! empty($out['rotation_enforce']) && $out['rotation_enforce'] !== 'false' ? 1 : 0;
        if (array_key_exists('rate_limit_per_min', $out)) { // empty → the default; never 0 (that would mean 1 request a minute)
            $out['rate_limit_per_min'] = $out['rate_limit_per_min'] === null ? self::DEFAULT_RATE : max(1, min(100000, (int) $out['rate_limit_per_min']));
        }
        if (array_key_exists('scopes', $out) && ! is_array($out['scopes'])) unset($out['scopes']);
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
