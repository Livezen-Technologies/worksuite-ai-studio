<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Services\JwtService;

/**
 * JwtAuthFilter — validates a Bearer access token on protected admin/dashboard
 * routes. On success the decoded payload is stashed in $_SERVER['ci_jwt_payload']
 * for downstream controllers (read via BaseApiController::jwtPayload()).
 *
 * Optional role gating via filter arguments, e.g. `['filter' => 'jwt:admin']`
 * or `jwt:admin,developer`. `super_admin` always passes.
 */
class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');
        $header   = $request->getHeaderLine('Authorization');

        if (empty($header) || ! str_starts_with($header, 'Bearer ')) {
            return $this->deny($response, 'Unauthorized: no token provided', 401);
        }

        $payload = (new JwtService())->decode(substr($header, 7));
        if (! $payload || ($payload['type'] ?? '') !== 'access') {
            return $this->deny($response, 'Unauthorized: invalid or expired token', 401);
        }

        // Coarse role gating. super_admin bypasses all role checks.
        if (! empty($arguments)) {
            $role = $payload['role'] ?? '';
            if ($role !== 'super_admin' && ! in_array($role, $arguments, true)) {
                return $this->deny($response, 'Forbidden: insufficient role', 403);
            }
        }

        $_SERVER['ci_jwt_payload'] = $payload;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function deny(ResponseInterface $response, string $message, int $code)
    {
        return $response
            ->setStatusCode($code)
            ->setJSON(['status' => 'error', 'message' => $message, 'data' => null]);
    }
}
