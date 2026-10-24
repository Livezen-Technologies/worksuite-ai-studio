<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\ApiKeyModel;

/**
 * ApiKeyFilter — authenticates external `/api/v1/*` gateway requests with an
 * API key + secret, enforcing status/expiry, IP allowlist and per-minute rate
 * limits. The validated key row is stashed in $_SERVER['ohub_api_key'].
 *
 * Credentials accepted as:
 *   - Headers:  X-Api-Key: ohub_…   X-Api-Secret: sk_…
 *   - Bearer:   Authorization: Bearer ohub_…:sk_…
 */
class ApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');
        [$key, $secret] = $this->credentials($request);

        if (! $key || ! $secret) {
            return $this->deny($response, 'Missing API key or secret.', 401);
        }

        $model = new ApiKeyModel();
        $row   = $model->findByKey($key);
        if (! $row || ! password_verify($secret, $row['secret_hash'])) {
            return $this->deny($response, 'Invalid API credentials.', 401);
        }
        if ($row['status'] !== 'active') {
            return $this->deny($response, 'This API key is disabled.', 403);
        }
        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return $this->deny($response, 'This API key has expired.', 403);
        }

        // IP allowlist.
        $allowedIps = $row['allowed_ips'] ? json_decode($row['allowed_ips'], true) : null;
        if (is_array($allowedIps) && $allowedIps && ! in_array($request->getIPAddress(), $allowedIps, true)) {
            return $this->deny($response, 'Your IP is not allowed for this key.', 403);
        }

        // Per-minute rate limit via the framework throttler.
        $throttler = service('throttler');
        $rate = max(1, (int) $row['rate_limit_per_min']);
        if ($throttler->check('gw_' . $row['id'], $rate, MINUTE) === false) {
            return $this->deny($response, 'Rate limit exceeded. Try again shortly.', 429);
        }

        $model->update($row['id'], ['last_used_at' => date('Y-m-d H:i:s')]);
        $_SERVER['ohub_api_key'] = $row;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
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
        return $response->setStatusCode($code)
            ->setJSON(['status' => 'error', 'message' => $message, 'data' => null]);
    }
}
