<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuditFilter — after-filter that records every mutating API request
 * (POST/PUT/PATCH/DELETE) to `audit_logs`, capturing the acting user (from the
 * JWT payload), method, path, response status and IP. Reads are ignored, except
 * downloads and exports (data leaving the system), which are recorded too.
 * Sign-in attempts record the email tried (never the password).
 */
class AuditFilter implements FilterInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];
    /** GET routes that hand out data: dataset exports, kits, files, manifests, gateway downloads. */
    private const DOWNLOAD = '#/(download|export|kit|manifest)$|/api/v1/(datasets/\d+/download|packages/[^/]+/files)#';

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        // Every call that came through the WorkSuite API Gateway is recorded, reads included, with who it was.
        if (\App\Libraries\Gateway\GatewayReceiver::caller() !== null) {
            self::recordGateway($method, '/' . ltrim($path, '/'), $response->getStatusCode());
            return;
        }
        $download = $method === 'GET' && preg_match(self::DOWNLOAD, $path);
        if (! in_array($method, self::MUTATING, true) && ! $download) {
            return;
        }

        // Skip noisy/irrelevant endpoints.
        if (str_contains($path, 'api/auth/refresh')) {
            return;
        }

        $payload = $_SERVER['ci_jwt_payload'] ?? [];
        $who = $payload['name'] ?? ($payload['email'] ?? null);
        if (str_contains($path, 'api/auth/login')) {
            $who = $this->attemptedEmail($request);
        }

        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                'user_id'    => $payload['sub'] ?? null,
                'user_name'  => $who,
                'action'     => $download ? 'download ' . $this->resource($path) : $this->action($method, $path),
                'method'     => $method,
                'path'       => '/' . ltrim($path, '/'),
                'status'     => $response->getStatusCode(),
                'ip'         => $request->getIPAddress(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the request, but a lost audit record must be visible.
            log_message('error', 'Audit log write failed for {method} {path}: {msg}', ['method' => $method, 'path' => $path, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Write an audit row directly. For handlers that stream a file and exit, where after-filters
     * never run (gateway model and dataset downloads).
     */
    public static function record(string $action, string $path, ?int $userId, ?string $who, int $status = 200): void
    {
        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                'user_id' => $userId, 'user_name' => $who !== null ? mb_substr($who, 0, 120) : null, 'action' => mb_substr($action, 0, 120),
                'method' => 'GET', 'path' => '/' . ltrim($path, '/'), 'status' => $status,
                'ip' => service('request')->getIPAddress(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Audit log write failed for {path}: {msg}', ['path' => $path, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Audit row for a call through the WorkSuite API Gateway: the key's owner, the gateway subject as the name, and
     * the assertion's jti (request id), sub, sub_type, cred, client_id, project, env and node in `meta`.
     */
    public static function recordGateway(string $method, string $path, int $status): void
    {
        $c = \App\Libraries\Gateway\GatewayReceiver::caller();
        if ($c === null || ! empty($_SERVER['ohub_gateway_audited'])) return;
        $_SERVER['ohub_gateway_audited'] = true;
        $key = $_SERVER['ohub_api_key'] ?? [];
        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                'user_id'    => isset($key['user_id']) ? (int) $key['user_id'] : null,
                'user_name'  => mb_substr('gateway ' . $c['sub'], 0, 120),
                'action'     => mb_substr('gateway ' . strtolower($method) . ' ' . preg_replace('#^/?api/#', '', $path), 0, 120),
                'method'     => strtoupper($method),
                'path'       => mb_substr('/' . ltrim($path, '/'), 0, 191),
                'status'     => $status,
                'ip'         => service('request')->getIPAddress(),
                'meta'       => \App\Libraries\Gateway\GatewayReceiver::auditMeta(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Audit log write failed for gateway {method} {path}: {msg}', ['method' => $method, 'path' => $path, 'msg' => $e->getMessage()]);
        }
    }

    private function attemptedEmail(RequestInterface $request): ?string
    {
        $body = json_decode((string) $request->getBody(), true);
        $email = is_array($body) ? ($body['email'] ?? null) : $request->getPost('email');
        return is_string($email) && $email !== '' ? 'sign-in attempt: ' . mb_substr($email, 0, 100) : null;
    }

    private function resource(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== '' && $s !== 'api' && $s !== 'v1'));
        return $segments[0] ?? 'resource';
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
