<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\JwtService;
use App\Services\TotpService;
use App\Services\WebAuthnService;
use RuntimeException;

/**
 * Auth endpoints — JWT login/refresh/profile/logout, plus 2FA and passkey sign-in.
 *
 *   POST /api/auth/login    { email, password }   -> { user, access_token, refresh_token }
 *                                                   or { mfa_required, mfa_token }        (TOTP is on)
 *                                                   or { mfa_setup_required, mfa_token }  (2FA enforced, not set up)
 *   POST /api/auth/mfa      { mfa_token, code }    -> session (code = authenticator code or recovery code)
 *   POST /api/auth/mfa/setup          { mfa_token }                     -> { secret, uri, setup_token }
 *   POST /api/auth/mfa/setup/confirm  { mfa_token, setup_token, code }  -> session + recovery_codes
 *   POST /api/auth/passkey/options    -> { options, challenge_token }
 *   POST /api/auth/passkey  { challenge_token, credential }            -> session
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

        $jwt = new JwtService();
        if (! empty($user['totp_enabled_at'])) {
            return $this->ok([
                'mfa_required' => true,
                'mfa_token'    => $jwt->generateStepToken('mfa', ['sub' => (int) $user['id']]),
            ], 'Enter the code from your authenticator app.');
        }
        if (! empty($user['two_factor_required'])) {
            return $this->ok([
                'mfa_setup_required' => true,
                'mfa_token'          => $jwt->generateStepToken('mfa_setup', ['sub' => (int) $user['id']], 900),
            ], 'Your account needs two-factor authentication. Set it up to continue.');
        }

        return $this->session((int) $user['id'], 'Welcome back!');
    }

    /** Second step of a password sign-in when TOTP is on. */
    public function mfa()
    {
        $in      = $this->input();
        $payload = (new JwtService())->decodeStep((string) ($in['mfa_token'] ?? ''), 'mfa');
        if (! $payload) {
            return $this->failure('Your sign-in expired. Please start again.', 401);
        }
        if ($r = $this->throttle((int) $payload['sub'])) return $r;

        $user = (new UserModel())->findActive((int) $payload['sub']);
        if (! $user || empty($user['totp_enabled_at'])) {
            return $this->failure('Your sign-in expired. Please start again.', 401);
        }
        if (! (new TotpService())->check($user, (string) ($in['code'] ?? ''))) {
            return $this->failure('That code is not valid. Try again.', 422, ['code' => 'Invalid code.']);
        }
        return $this->session((int) $user['id'], 'Welcome back!');
    }

    /** Enforced 2FA: start authenticator setup during sign-in. */
    public function mfaSetup()
    {
        $jwt     = new JwtService();
        $payload = $jwt->decodeStep((string) ($this->input()['mfa_token'] ?? ''), 'mfa_setup');
        $user    = $payload ? (new UserModel())->findActive((int) $payload['sub']) : null;
        if (! $user) {
            return $this->failure('Your sign-in expired. Please start again.', 401);
        }
        $totp   = new TotpService();
        $secret = $totp->generateSecret();
        return $this->ok([
            'secret'      => $secret,
            'uri'         => $totp->uri($secret, $user['email']),
            'setup_token' => $jwt->generateStepToken('totp_setup', ['sub' => (int) $user['id'], 'secret' => $secret], 900),
        ]);
    }

    public function mfaSetupConfirm()
    {
        $in      = $this->input();
        $jwt     = new JwtService();
        $payload = $jwt->decodeStep((string) ($in['mfa_token'] ?? ''), 'mfa_setup');
        $setup   = $jwt->decodeStep((string) ($in['setup_token'] ?? ''), 'totp_setup');
        if (! $payload || ! $setup || (int) $setup['sub'] !== (int) $payload['sub']) {
            return $this->failure('Your sign-in expired. Please start again.', 401);
        }
        if ($r = $this->throttle((int) $payload['sub'])) return $r;
        if (! (new UserModel())->findActive((int) $payload['sub'])) {
            return $this->failure('Account is no longer active.', 401);
        }

        $totp = new TotpService();
        $step = $totp->verify((string) $setup['secret'], (string) ($in['code'] ?? ''));
        if ($step === null) {
            return $this->failure('That code is not valid. Check the time on your phone and try again.', 422, ['code' => 'Invalid code.']);
        }
        $codes = $totp->enable((int) $payload['sub'], (string) $setup['secret'], $step);
        return $this->session((int) $payload['sub'], 'Two-factor authentication is on.', ['recovery_codes' => $codes]);
    }

    public function passkeyOptions()
    {
        try {
            $wa = new WebAuthnService($this->request);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 400);
        }
        $challenge = WebAuthnService::challenge();
        return $this->ok([
            'options' => [
                'challenge'        => $challenge,
                'rpId'             => $wa->rpId(),
                'timeout'          => 120000,
                'userVerification' => 'required',
                'allowCredentials' => [],
            ],
            'challenge_token' => (new JwtService())->generateStepToken('webauthn_get', ['challenge' => $challenge], 180),
        ]);
    }

    public function passkey()
    {
        $in      = $this->input();
        $payload = (new JwtService())->decodeStep((string) ($in['challenge_token'] ?? ''), 'webauthn_get');
        $cred    = is_array($in['credential'] ?? null) ? $in['credential'] : [];
        if (! $payload) {
            return $this->failure('The passkey request expired. Please try again.', 401);
        }

        $db  = \Config\Database::connect();
        $row = $db->table('user_passkeys')->where('credential_id', (string) ($cred['id'] ?? ''))->get()->getRowArray();
        if (! $row) {
            return $this->failure('This passkey is not registered with AI Studio.', 401);
        }
        $user = (new UserModel())->findActive((int) $row['user_id']);
        if (! $user) {
            return $this->failure('Account is no longer active.', 401);
        }
        $handle = WebAuthnService::b64uDecode((string) ($cred['response']['userHandle'] ?? ''));
        if ($handle !== '' && $handle !== (string) $user['id']) {
            return $this->failure('This passkey is not registered with AI Studio.', 401);
        }

        try {
            $count = (new WebAuthnService($this->request))->verifyAssertion($cred, (string) $payload['challenge'], $row);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 401);
        }
        $db->table('user_passkeys')->where('id', $row['id'])->update(['sign_count' => $count, 'last_used_at' => date('Y-m-d H:i:s')]);

        return $this->session((int) $user['id'], 'Welcome back!');
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

    /** Issue access + refresh tokens for a fully signed-in user. */
    private function session(int $userId, string $message, array $extra = [])
    {
        $users = new UserModel();
        $users->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);

        $full = $users->withRoleAndPermissions($userId);
        $jwt  = new JwtService();

        return $this->ok([
            'user'          => $full,
            'access_token'  => $jwt->generateAccessToken([
                'id' => $full['id'], 'email' => $full['email'], 'name' => $full['name'],
                'role' => $full['role'] ?? 'viewer',
            ]),
            'refresh_token' => $jwt->generateRefreshToken($userId),
            'token_type'    => 'Bearer',
            'expires_in'    => $jwt->accessTtl(),
        ] + $extra, $message);
    }

    /** 5 code attempts per user per minute. */
    private function throttle(int $userId)
    {
        return service('throttler')->check("auth-mfa-{$userId}", 5, MINUTE)
            ? null
            : $this->failure('Too many attempts. Wait a minute and try again.', 429);
    }
}
