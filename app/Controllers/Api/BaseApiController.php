<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/**
 * Shared base for every OllamaHub JSON API controller.
 *
 * Provides the standard `{ status, message, data }` envelope plus helpers to
 * read the authenticated user from the JWT payload (set by JwtAuthFilter).
 *
 * NOTE: never declare a method named validate() here — it collides with
 * CodeIgniter\Controller::validate() and 500s every route. Use validateFields().
 */
abstract class BaseApiController extends ResourceController
{
    protected $format = 'json';

    /** Decoded JWT payload for the current request (or [] on public routes). */
    protected function jwtPayload(): array
    {
        return $_SERVER['ci_jwt_payload'] ?? [];
    }

    protected function userId(): int
    {
        return (int) ($this->jwtPayload()['sub'] ?? 0);
    }

    protected function userRole(): string
    {
        return (string) ($this->jwtPayload()['role'] ?? '');
    }

    protected function isSuperAdmin(): bool
    {
        return $this->userRole() === 'super_admin';
    }

    /** Permission slugs granted to the current user's role (cached per request). */
    protected function userPermissions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $rows = \Config\Database::connect()->table('role_permissions rp')
            ->select('p.name')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->join('roles r', 'r.id = rp.role_id')
            ->where('r.name', $this->userRole())
            ->get()->getResultArray();
        return $cache = array_column($rows, 'name');
    }

    /** True when the current user may perform $slug (admins bypass). */
    protected function can(string $slug): bool
    {
        if (in_array($this->userRole(), ['super_admin', 'admin'], true)) {
            return true;
        }
        return in_array($slug, $this->userPermissions(), true);
    }

    /**
     * Guard a handler behind a permission. Returns a 403 Response to bail with,
     * or null when allowed:  if ($r = $this->requirePermission('servers.manage')) return $r;
     */
    protected function requirePermission(string $slug)
    {
        return $this->can($slug) ? null : $this->failure('You do not have permission to do this.', 403);
    }

    /** Success envelope. */
    protected function ok($data = null, string $message = 'OK', int $status = 200)
    {
        return $this->respond([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /** Error envelope. Named failure() to avoid colliding with ResourceController::fail(). */
    protected function failure(string $message, int $status = 422, $data = null)
    {
        return $this->respond([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /** Validate input against rules; returns field=>message map (empty if OK). */
    protected function validateFields(array $data, array $rules): array
    {
        $validation = \Config\Services::validation();
        $validation->setRules($rules);
        return $validation->run($data) ? [] : $validation->getErrors();
    }

    /** Read a JSON body (falling back to form post). */
    protected function input(): array
    {
        return $this->request->getJSON(true) ?? $this->request->getPost() ?? [];
    }

    /** Paging helper: returns [page, perPage, offset] from query string. */
    protected function paging(int $defaultPer = 25): array
    {
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $per  = min(200, max(1, (int) ($this->request->getGet('per_page') ?? $defaultPer)));
        return [$page, $per, ($page - 1) * $per];
    }
}
