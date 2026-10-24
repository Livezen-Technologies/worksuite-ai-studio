<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuditFilter — after-filter that records every mutating API request
 * (POST/PUT/PATCH/DELETE) to `audit_logs`, capturing the acting user (from the
 * JWT payload), method, path, response status and IP. Read requests are ignored.
 */
class AuditFilter implements FilterInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        if (! in_array($method, self::MUTATING, true)) {
            return;
        }

        $path = $request->getUri()->getPath();
        // Skip noisy/irrelevant endpoints.
        if (str_contains($path, 'api/auth/refresh')) {
            return;
        }

        $payload = $_SERVER['ci_jwt_payload'] ?? [];

        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                'user_id'    => $payload['sub'] ?? null,
                'user_name'  => $payload['name'] ?? ($payload['email'] ?? null),
                'action'     => $this->action($method, $path),
                'method'     => $method,
                'path'       => '/' . ltrim($path, '/'),
                'status'     => $response->getStatusCode(),
                'ip'         => $request->getIPAddress(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the request.
        }
    }

    /** Derive a human action label from the verb + resource path. */
    private function action(string $method, string $path): string
    {
        $verb = ['POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'][$method] ?? $method;
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== '' && $s !== 'api'));
        $resource = $segments[0] ?? 'resource';
        // e.g. login is a POST to auth/login
        if ($resource === 'auth') {
            return $segments[1] ?? 'auth';
        }
        return "{$verb} {$resource}";
    }
}
