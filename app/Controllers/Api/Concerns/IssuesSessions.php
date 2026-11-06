<?php

namespace App\Controllers\Api\Concerns;

use App\Controllers\Api\AuthController;
use App\Models\UserModel;
use App\Services\JwtService;

/**
 * Issues a signed-in session (JSON tokens + the HttpOnly refresh cookie). Shared by sign-in
 * (AuthController) and the self-service password change (SecurityController).
 */
trait IssuesSessions
{
    /**
     * Set (or, with $maxAge < 0, clear) the HttpOnly refresh cookie, scoped to /api/auth.
     * Secure over HTTPS and always in production; plain HTTP is allowed only for local dev.
     */
    protected function refreshCookie(string $value, int $maxAge): void
    {
        $secure = $this->request->isSecure() || ENVIRONMENT === 'production';
        $this->response->setCookie([
            'name'     => AuthController::REFRESH_COOKIE,
            'value'    => $value,
            'expire'   => $maxAge < 0 ? -1 : $maxAge,
            'path'     => '/api/auth',
            'prefix'   => '',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** Issue access + refresh tokens for a fully signed-in user. */
    protected function session(int $userId, string $message, array $extra = [])
    {
        $users = new UserModel();
        $users->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);

        $full = $users->withRoleAndPermissions($userId);
        $jwt  = new JwtService();
        $refresh = $jwt->generateRefreshToken($userId, (int) ($full['token_version'] ?? 0));
        $this->refreshCookie($refresh, (new JwtService())->refreshTtl());

        return $this->ok([
            'user'          => $full,
            'access_token'  => $jwt->generateAccessToken([
                'id' => $full['id'], 'email' => $full['email'], 'name' => $full['name'],
                'role' => $full['role'] ?? 'viewer', 'token_version' => $full['token_version'] ?? 0,
            ]),
            // Still returned for API clients; the web app keeps only the HttpOnly cookie.
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => $jwt->accessTtl(),
        ] + $extra, $message);
    }
}
