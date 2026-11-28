<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\ApiKeyModel;
use App\Services\ApiTokenService;
use App\Libraries\Gateway\GatewayAssertion;
use App\Libraries\Gateway\GatewayAssertionError;
use App\Libraries\Gateway\GatewayReceiver;

/**
 * ApiKeyFilter — authenticates external `/api/v1/*` gateway requests with an
 * API key + secret, enforcing status/expiry, IP allowlist and per-minute rate
 * limits. The validated key row is stashed in $_SERVER['ohub_api_key'].
 *
 * Credentials accepted as:
 *   - Headers:  X-Api-Key: ohub_…   X-Api-Secret: sk_…
 *   - Bearer:   Authorization: Bearer ohub_…:sk_…
 *   - Bearer:   Authorization: Bearer <jwt>   (short-lived access token, see ApiTokenService)
 *   - Gateway:  Authorization: WS-API-Gateway <jwt>   (the WorkSuite API Gateway's signed assertion, only with
 *               APIM_GATEWAY_ENABLED=true; see docs/api-gateway.md). The caller maps to the API key bound to its
 *               API console credential, narrowed to the assertion's scopes; every check below still applies.
 */
class ApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');
        $model    = new ApiKeyModel();
        $token    = $this->bearerJwt($request);
        GatewayReceiver::reset();
        $viaGateway = GatewayAssertion::hasScheme($request->getHeaderLine('Authorization'));

        if ($viaGateway) {
            $row = $this->gatewayKey($request, $response, $model);
            if ($row instanceof ResponseInterface) {
                return $row;
            }
        } elseif ($token !== null) {
            // Short-lived JWT access token issued for a key (dashboard or OAuth client_credentials).
            $claims = (new ApiTokenService())->decode($token);
            $row    = $claims ? $model->find((int) ($claims['kid'] ?? 0)) : null;
            if (! $row || (int) ($row['token_version'] ?? 0) !== (int) ($claims['kv'] ?? -1)) {
                return $this->deny($response, 'Invalid or expired access token.', 401);
            }
            // A token never widens its key: narrow scopes to what was granted at issue.
            $row['scopes'] = implode(',', array_intersect((array) ($claims['scopes'] ?? []), explode(',', (string) $row['scopes'])));
        } else {
            [$key, $secret] = $this->credentials($request);
            if (! $key || ! $secret) {
                $hasBearer = str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ');
                return $this->deny($response, $hasBearer
                    ? 'Invalid API key. Use "key:secret" (ohub_…:sk_…) or an access token as the Bearer value.'
                    : 'Missing API key or secret.', 401);
            }
            // Failed checks are throttled per IP before bcrypt runs: key ids are not secret, and each
            // wrong secret costs a password_verify (audit S6).
            $bucket = 'gwfail_' . md5($request->getIPAddress());
            if ((int) cache($bucket) >= 30) {
                return $this->deny($response, 'Too many failed attempts. Try again in a minute.', 429);
            }
            $row = $model->findByKey($key);
            if (! $row || ! ApiKeyModel::secretMatches($row, $secret)) {
                cache()->save($bucket, (int) cache($bucket) + 1, 60);
                return $this->deny($response, 'Invalid API credentials.', 401);
            }
        }
        if (! empty($row['revoked_at']) || $row['status'] === 'revoked') {
            return $this->deny($response, 'This API key has been revoked.', 403);
        }
        if ($row['status'] !== 'active') {
            return $this->deny($response, 'This API key is disabled.', 403);
        }
        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return $this->deny($response, 'This API key has expired.', 403);
        }

        // A key dies with its owner: the owning user must still exist, be active and not deleted.
        if (! empty($row['user_id'])) {
            $owner = \Config\Database::connect()->table('users')->select('status, deleted_at')->where('id', (int) $row['user_id'])->get()->getRowArray();
            if (! $owner || ($owner['status'] ?? null) !== 'active' || ! empty($owner['deleted_at'])) {
                return $this->deny($response, 'This API key is disabled.', 403);
            }
        }

        // IP allowlist (a gateway caller arrives from the gateway node: keys with an allowlist were refused above).
        $allowedIps = $row['allowed_ips'] ? json_decode($row['allowed_ips'], true) : null;
        if (! $viaGateway && is_array($allowedIps) && $allowedIps && ! in_array($request->getIPAddress(), $allowedIps, true)) {
            return $this->deny($response, 'Your IP is not allowed for this key.', 403);
        }

        // Per-minute rate limit via the framework throttler.
        $throttler = service('throttler');
        $rate = max(1, (int) $row['rate_limit_per_min']);
        if ($throttler->check('gw_' . $row['id'], $rate, MINUTE) === false) {
            return $this->deny($response, 'Rate limit exceeded. Try again shortly.', 429);
        }

        // last used: written at most once a minute per key, not on every call
        if (empty($row['last_used_at']) || strtotime($row['last_used_at']) < time() - 60) {
            $model->update($row['id'], ['last_used_at' => date('Y-m-d H:i:s')]);
        }
        $_SERVER['ohub_api_key'] = $row;
        if ($viaGateway) {
            // Streams and file downloads exit before after-filters run: audit them at shutdown instead.
            register_shutdown_function(static function (): void {
                if (GatewayReceiver::caller() !== null && empty($_SERVER['ohub_gateway_audited'])) {
                    $req = service('request');
                    AuditFilter::recordGateway($req->getMethod(), GatewayReceiver::requestPath($req), http_response_code() ?: 200);
                }
            });
        }
    }

    /**
     * The API key row for a gateway caller, or the refusal. `sub_type: credential` callers (API keys, service
     * accounts, certificates and client-credentials apps in the API console) map to the key whose
     * gateway_credential_id is their credential, in an allowed console organization. `sub_type: user` callers map to
     * the key bound to the OAuth app's credential that belongs to the AI Studio user already linked to that OneAuth
     * subject (users.wsc_sub); nothing is created or linked here. The key is then narrowed to the assertion's scopes.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function gatewayKey(RequestInterface $request, ResponseInterface $response, ApiKeyModel $model)
    {
        /** @var GatewayReceiver $gw */
        $gw = service('gatewayReceiver');
        if (! $gw->enabled()) {
            return $this->gatewayDeny($response, 'GATEWAY_DISABLED', 'The API gateway is not enabled for AI Studio.');
        }
        try {
            $c = $gw->verify($request);
        } catch (GatewayAssertionError $e) {
            log_message('notice', 'Gateway assertion refused ({reason}): {msg}', ['reason' => $e->reason, 'msg' => $e->getMessage()]);
            return $this->gatewayDeny($response, $e->reason, 'Gateway assertion refused.');
        } catch (\Throwable $e) {
            log_message('error', 'Gateway assertion check failed: {msg}', ['msg' => $e->getMessage()]);
            return $this->gatewayDeny($response, 'error', 'Gateway assertion could not be checked.');
        }

        $db = \Config\Database::connect();
        $rows = [];
        if ($c['sub_type'] === 'credential') {
            $cred = str_starts_with($c['sub'], 'cred:') ? substr($c['sub'], 5) : '';
            if ($cred === '' || ($c['cred'] !== '' && $c['cred'] !== $cred)) {
                return $this->gatewayDeny($response, 'unmapped', 'The gateway credential is malformed.');
            }
            if (! $gw->orgAllowed($c['org'])) {
                return $this->gatewayDeny($response, 'unmapped', 'This API console organization may not call AI Studio.');
            }
            $rows = $db->table('api_keys')->where('gateway_credential_id', $cred)->get()->getResultArray();
        } elseif ($c['sub_type'] === 'user') {
            $user = $c['sub'] === '' ? null : $db->table('users')->select('id')->where('wsc_sub', $c['sub'])
                ->where('status', 'active')->where('deleted_at', null)->get()->getRowArray();
            if ($user && $c['cred'] !== '') {
                $rows = $db->table('api_keys')->where('gateway_credential_id', $c['cred'])->where('user_id', (int) $user['id'])->get()->getResultArray();
            }
        } else {
            return $this->gatewayDeny($response, 'sub_type', 'Anonymous callers are not accepted.');
        }
        if (count($rows) !== 1) {
            return $this->gatewayDeny($response, 'unmapped', 'No AI Studio API key is bound to this caller.');
        }
        $row = $model->find((int) $rows[0]['id']) ?? $rows[0];

        $need = GatewayReceiver::scopeFor($request->getMethod(), GatewayReceiver::requestPath($request));
        if ($need === null) {
            return $this->gatewayDeny($response, 'route', 'This endpoint is not offered through the API gateway.', 403);
        }
        if (! in_array($need, $c['scopes'], true)) {
            return $this->gatewayDeny($response, 'insufficient_scope', "The gateway credential lacks the '{$need}' scope.", 403);
        }
        $allowedIps = ! empty($row['allowed_ips']) ? json_decode((string) $row['allowed_ips'], true) : null;
        if (is_array($allowedIps) && $allowedIps) {
            return $this->gatewayDeny($response, 'ip_allowlist', 'A key with an IP allowlist cannot be used through the API gateway.', 403);
        }
        // Never wider than the key, never wider than the assertion.
        $row['scopes'] = GatewayReceiver::narrowScopes((string) ($row['scopes'] ?? ''), $c['scopes']);
        GatewayReceiver::setCaller($c);

        return $row;
    }

    /** A refused gateway call: the reason code, and `WWW-Authenticate: WS-API-Gateway` on a 401. */
    private function gatewayDeny(ResponseInterface $response, string $reason, string $message, int $code = 401)
    {
        $r = $this->deny($response, "{$message} ({$reason})", $code);
        $r->setHeader('X-WS-Gateway-Reason', $reason);
        if ($code === 401) {
            $r->setHeader('WWW-Authenticate', GatewayAssertion::SCHEME);
        }
        return $r;
    }

    /** Every gateway answer says how much of the key's rate limit and quotas is left. */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $key = $_SERVER['ohub_api_key'] ?? null;
        if (! $key) return;
        foreach ((new \App\Services\ApiGatewayService())->quotaHeaders($key) as $k => $v) $response->setHeader($k, $v);
        // Token use for the gateway's metering (contract §4), to gateway callers only; the gateway strips them.
        foreach (GatewayReceiver::meteringHeaders() as $k => $v) $response->setHeader($k, $v);
        return $response;
    }

    /** A Bearer value that looks like a JWT (three dot-separated parts, no key:secret colon). */
    private function bearerJwt(RequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (! str_starts_with($auth, 'Bearer ')) return null;
        $tok = trim(substr($auth, 7));
        return (! str_contains($tok, ':') && substr_count($tok, '.') === 2) ? $tok : null;
    }

    /** Extract [key, secret] from headers or a Bearer "key:secret" token. */
    private function credentials(RequestInterface $request): array
    {
        $key    = $request->getHeaderLine('X-Api-Key');
        $secret = $request->getHeaderLine('X-Api-Secret');
        if ($key && $secret) {
            return [trim($key), trim($secret)];
        }
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ') && str_contains($auth, ':')) {
            [$k, $s] = explode(':', substr($auth, 7), 2);
            return [trim($k), trim($s)];
        }
        return [null, null];
    }

    private function deny(ResponseInterface $response, string $message, int $code)
    {
        // OpenAI-compatible routes answer in the OpenAI error shape so SDKs surface the message.
        $path = trim(service('request')->getUri()->getPath(), '/');
        if (str_contains($path, 'api/openai/') || str_ends_with($path, 'api/v1/chat/completions')) {
            return $response->setStatusCode($code)->setJSON(\App\Controllers\Api\Gateway\OpenAIController::errorBody($message, $code, match ($code) {
                401 => 'invalid_api_key', 429 => 'rate_limit_exceeded', default => null,
            }));
        }
        return $response->setStatusCode($code)
            ->setJSON(['status' => 'error', 'message' => $message, 'data' => null]);
    }
}
