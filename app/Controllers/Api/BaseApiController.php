<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/**
 * Shared base for every WorkSuite AI Studio JSON API controller.
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
    private ?array $permissionCache = null;

    protected function userPermissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }
        $rows = \Config\Database::connect()->table('role_permissions rp')
            ->select('p.name')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->join('roles r', 'r.id = rp.role_id')
            ->where('r.name', $this->userRole())
            ->get()->getResultArray();
        return $this->permissionCache = array_column($rows, 'name');
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

    /**
     * Shared upload check: allowed extensions, a size cap and, for text formats, valid UTF-8 text
     * (rejects binaries renamed to .csv). Returns an error message, or null when the file is fine.
     */
    protected function uploadProblem($file, array $extensions, int $maxMb, bool $text = false): ?string
    {
        if (! $file || ! $file->isValid()) {
            return 'No file received (check the upload size limit).';
        }
        $ext = strtolower($file->getClientExtension() ?: pathinfo($file->getClientName(), PATHINFO_EXTENSION));
        if (! in_array($ext, $extensions, true)) {
            return 'Unsupported file type. Use ' . implode(', ', $extensions) . '.';
        }
        if ($file->getSize() > $maxMb * 1048576) {
            return "The file is over {$maxMb} MB.";
        }
        if ($text) {
            $head = (string) file_get_contents($file->getTempName(), false, null, 0, 65536);
            // Trim a possibly cut multibyte character at the end of the sample before checking.
            if (str_contains($head, "\0") || ! mb_check_encoding(substr($head, 0, strlen($head) - 4) ?: $head, 'UTF-8')) {
                return 'The file does not look like UTF-8 text.';
            }
        }
        return null;
    }

    /**
     * Privilege ceiling for user and role administration: a caller may only hand out, or act on
     * someone holding, a role that is no more powerful than their own. super_admin may do
     * anything; admin may manage every role except super_admin; anyone else only roles whose
     * permissions they themselves hold.
     */
    protected function withinMyPrivileges(?array $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if (! $role) {
            return false;
        }
        $name = (string) ($role['name'] ?? '');
        if ($name === 'super_admin') {
            return false;
        }
        if ($this->userRole() === 'admin') {
            return true;
        }
        if ($name === 'admin') {
            return false;
        }
        $theirs = (new \App\Models\UserModel())->permissionsForRole((int) $role['id']);
        return ! array_diff($theirs, $this->userPermissions());
    }

    /** True when every permission id is one the caller holds (admins and super admins hold all). */
    protected function holdsPermissionIds(array $ids): bool
    {
        if (in_array($this->userRole(), ['super_admin', 'admin'], true)) {
            return true;
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) {
            return true;
        }
        $names = array_column(\Config\Database::connect()->table('permissions')->select('name')->whereIn('id', $ids)->get()->getResultArray(), 'name');
        return count($names) === count(array_unique($ids)) && ! array_diff($names, $this->userPermissions());
    }
}
