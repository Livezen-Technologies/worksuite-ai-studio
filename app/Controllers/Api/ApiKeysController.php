<?php

namespace App\Controllers\Api;

use App\Models\ApiKeyModel;
use App\Models\ApiLogModel;

/**
 * API Keys management (dashboard side).
 *
 *   GET    /api/apikeys            list
 *   POST   /api/apikeys            create (returns the secret ONCE)   (apikeys.manage)
 *   PUT    /api/apikeys/{id}       update settings                    (apikeys.manage)
 *   POST   /api/apikeys/{id}/rotate  new secret (returned once)       (apikeys.manage)
 *   DELETE /api/apikeys/{id}       delete                             (apikeys.manage)
 */
class ApiKeysController extends BaseApiController
{
    private function keys(): ApiKeyModel { return new ApiKeyModel(); }

    public function index()
    {
        if ($r = $this->requirePermission('apikeys.view')) return $r;
        $m    = $this->keys();
        $rows = array_map([$m, 'publicRow'], $m->orderBy('id', 'DESC')->findAll());
        // Attach 30-day usage for each key.
        $logs = new ApiLogModel();
        foreach ($rows as &$row) {
            $row['tokens_30d'] = $logs->tokensSince((int) $row['id'], date('Y-m-d H:i:s', strtotime('-30 days')));
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
        $m    = $this->keys();
        $cred = $m->mint();
        $id   = $m->insert($this->fields($in, [
            'name'        => $in['name'],
            'user_id'     => $this->userId(),
            'api_key'     => $cred['key'],
            'secret_hash' => $cred['hash'],
            'secret_prefix' => $cred['prefix'],
        ]), true);

        $row = $m->publicRow($m->find($id));
        // The plaintext secret is shown exactly once.
        $row['secret'] = $cred['secret'];
        return $this->ok($row, 'API key created. Copy the secret now — it won’t be shown again.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        $m = $this->keys();
        if (! $m->find((int) $id)) return $this->failure('API key not found.', 404);
        $m->update((int) $id, $this->fields($this->input()));
        return $this->ok($m->publicRow($m->find((int) $id)), 'API key updated.');
    }

    public function rotate($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        $m = $this->keys();
        if (! $m->find((int) $id)) return $this->failure('API key not found.', 404);
        $cred = $m->mint();
        $m->update((int) $id, ['secret_hash' => $cred['hash'], 'secret_prefix' => $cred['prefix']]);
        $row = $m->publicRow($m->find((int) $id));
        $row['secret'] = $cred['secret'];
        return $this->ok($row, 'Secret rotated. Copy it now — it won’t be shown again.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('apikeys.manage')) return $r;
        $m = $this->keys();
        if (! $m->find((int) $id)) return $this->failure('API key not found.', 404);
        $m->delete((int) $id);
        return $this->ok(null, 'API key deleted.');
    }

    /** Whitelist editable fields; JSON-encode array fields. */
    private function fields(array $in, array $base = []): array
    {
        $out = $base;
        foreach (['name', 'scopes', 'rate_limit_per_min', 'daily_token_limit', 'monthly_token_limit', 'status', 'expires_at'] as $f) {
            if (array_key_exists($f, $in)) {
                $out[$f] = $in[$f] === '' ? null : $in[$f];
            }
        }
        if (is_array($out['scopes'] ?? null)) {
            $out['scopes'] = implode(',', $out['scopes']);
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
