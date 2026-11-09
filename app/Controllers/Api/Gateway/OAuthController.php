<?php

namespace App\Controllers\Api\Gateway;

use App\Controllers\Api\BaseApiController;
use App\Models\ApiKeyModel;
use App\Services\ApiTokenService;

/**
 * OAuth 2.0 token endpoint (RFC 6749 §4.4, client credentials).
 *
 *   POST /api/v1/oauth/token
 *     grant_type=client_credentials&client_id=ohub_…&client_secret=sk_…[&scope=chat models][&expires_in=3600]
 *     (or HTTP Basic client_id:client_secret)
 *
 * Returns { access_token, token_type: "Bearer", expires_in, scope } — a short-lived JWT the
 * /api/v1 gateway accepts as `Authorization: Bearer <token>`.
 */
class OAuthController extends BaseApiController
{
    public function token()
    {
        $in = $this->input();
        if (($in['grant_type'] ?? '') !== 'client_credentials') {
            return $this->oauthError('unsupported_grant_type', 'Only grant_type=client_credentials is supported.', 400);
        }
        [$id, $secret] = $this->client($in);
        if (! $id || ! $secret) {
            return $this->oauthError('invalid_client', 'client_id and client_secret are required.', 401);
        }
        $ip = $this->request->getIPAddress(); // per IP as well as per key and IP: rotating key ids gains nothing
        if (service('throttler')->check('oauth_ip_' . md5($ip), 60, MINUTE) === false
            || service('throttler')->check('oauth_' . md5($id . '|' . $ip), 20, MINUTE) === false) {
            return $this->oauthError('slow_down', 'Too many token requests. Try again shortly.', 429);
        }
        $row = (new ApiKeyModel())->findByKey($id);
        if (! $row || ! password_verify($secret, (string) $row['secret_hash'])) {
            return $this->oauthError('invalid_client', 'Invalid client credentials.', 401);
        }
        if (ApiKeyModel::state($row) !== 'active') {
            return $this->oauthError('invalid_client', 'This API key is not active.', 401);
        }
        if (! empty($row['user_id'])) {
            $owner = \Config\Database::connect()->table('users')->select('status, deleted_at')->where('id', (int) $row['user_id'])->get()->getRowArray();
            if (! $owner || ($owner['status'] ?? null) !== 'active' || ! empty($owner['deleted_at'])) {
                return $this->oauthError('invalid_client', 'This API key is not active.', 401);
            }
        }
        $scopes = trim((string) ($in['scope'] ?? '')) !== '' ? preg_split('/[\s,]+/', trim((string) $in['scope'])) : null;
        $tok    = (new ApiTokenService())->issue($row, $scopes, isset($in['expires_in']) ? (int) $in['expires_in'] : null);
        unset($tok['expires_at']);
        return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setJSON($tok);
    }

    /** client_id / client_secret from the body or HTTP Basic auth. */
    private function client(array $in): array
    {
        $auth = $this->request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Basic ')) {
            $pair = base64_decode(substr($auth, 6), true);
            if ($pair !== false && str_contains($pair, ':')) {
                return array_map('trim', explode(':', $pair, 2));
            }
        }
        return [trim((string) ($in['client_id'] ?? '')), trim((string) ($in['client_secret'] ?? ''))];
    }

    private function oauthError(string $code, string $desc, int $status)
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
            ->setJSON(['error' => $code, 'error_description' => $desc]);
    }
}
