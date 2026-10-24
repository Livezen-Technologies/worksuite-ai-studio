<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\JwtService;

/**
 * Auth endpoints — JWT login/refresh/profile/logout.
 *
 *   POST /api/auth/login    { email, password }   -> { user, access_token, refresh_token }
 *   POST /api/auth/refresh  { refresh_token }      -> { access_token }
 *   GET  /api/auth/profile  (Bearer)               -> { user }
 *   POST /api/auth/logout   (Bearer)               -> ok (stateless)
 */
class AuthController extends BaseApiController
{
    public function login()
    {
        $in = $this->input();
        if ($errors = $this->validateFields($in, [
            'email'    => 'required|valid_email',
            'password' => 'required',
        ])) {
            return $this->failure('Please provide a valid email and password.', 422, $errors);
        }

        $users = new UserModel();
        $user  = $users->findByEmail(trim((string) $in['email']));
        if (! $user || ! password_verify((string) $in['password'], $user['password_hash'])) {
            return $this->failure('Invalid email or password.', 401);
        }

        $users->update($user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        $full = $users->withRoleAndPermissions((int) $user['id']);
        $jwt  = new JwtService();

        return $this->ok([
            'user'          => $full,
            'access_token'  => $jwt->generateAccessToken([
                'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'],
                'role' => $full['role'] ?? 'viewer',
            ]),
            'refresh_token' => $jwt->generateRefreshToken((int) $user['id']),
            'token_type'    => 'Bearer',
            'expires_in'    => $jwt->accessTtl(),
        ], 'Welcome back!');
    }

    public function refresh()
    {
        $in    = $this->input();
        $token = (string) ($in['refresh_token'] ?? '');
        $jwt   = new JwtService();
        $payload = $token ? $jwt->decode($token) : null;

        if (! $payload || ($payload['type'] ?? '') !== 'refresh') {
            return $this->failure('Invalid or expired refresh token.', 401);
        }

        $full = (new UserModel())->withRoleAndPermissions((int) $payload['sub']);
        if (! $full || ($full['status'] ?? '') !== 'active') {
            return $this->failure('Account is no longer active.', 401);
        }

        return $this->ok([
            'access_token' => $jwt->generateAccessToken([
                'id' => $full['id'], 'email' => $full['email'], 'name' => $full['name'],
                'role' => $full['role'] ?? 'viewer',
            ]),
            'token_type' => 'Bearer',
            'expires_in' => $jwt->accessTtl(),
        ], 'Token refreshed.');
    }

    /** Requires JwtAuthFilter (Bearer access token). */
    public function profile()
    {
        $full = (new UserModel())->withRoleAndPermissions($this->userId());
        if (! $full) {
            return $this->failure('User not found.', 404);
        }
        return $this->ok(['user' => $full]);
    }

    public function logout()
    {
        // Stateless JWT — client discards the token. (Blacklist can be added later.)
        return $this->ok(null, 'Signed out.');
    }
}
