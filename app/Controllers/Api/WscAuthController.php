<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\JwtService;
use App\Services\WscOidcService;
use RuntimeException;

/**
 * "Sign in with WorkSuite Cloud" for AI Studio admins (staff SSO contract, part 3).
 *
 *   GET /api/auth/wsc/config              -> { enabled }            (the SPA hides the button when off)
 *   GET /api/auth/wsc/start?from=&silent=1 -> 302 to OneAuth, flow state in a short-lived signed cookie
 *   GET /api/auth/wsc/callback?code&state -> 302 to /login?wsc=ok (refresh cookie set) or /login?wsc_error=…
 *
 * Only an existing, active local admin (role super_admin or admin) whose email matches the verified
 * WorkSuite Cloud email (case-insensitive) is signed in. Users are never created here.
 */
class WscAuthController extends BaseApiController
{
    use Concerns\IssuesSessions;

    public const FLOW_COOKIE = 'aistudio_wsc';
    public const ADMIN_ROLES = ['super_admin', 'admin'];

    private function oidc(): WscOidcService { return service('wscOidc'); }

    public function config()
    {
        return $this->ok(['enabled' => $this->oidc()->enabled()]);
    }

    public function start()
    {
        $oidc = $this->oidc();
        if (! $oidc->enabled()) return $this->toLogin(['wsc_error' => 'WorkSuite Cloud sign-in is not set up.']);
        if (! service('throttler')->check('auth-wsc-' . md5($this->request->getIPAddress()), 30, MINUTE)) {
            return $this->toLogin(['wsc_error' => 'Too many attempts. Wait a minute and try again.']);
        }

        $silent = $this->request->getGet('silent') === '1';
        $from   = self::safePath((string) $this->request->getGet('from'));
        [$verifier, $challenge] = WscOidcService::pkce();
        $state = WscOidcService::random();
        $nonce = WscOidcService::random();

        try {
            $url = $oidc->authorizeUrl($state, $nonce, $challenge, $silent);
        } catch (RuntimeException $e) {
            return $this->toLogin(['wsc_error' => $e->getMessage()]);
        }
        $flow = (new JwtService())->generateStepToken('wsc', compact('state', 'nonce', 'verifier', 'from', 'silent'), 600);
        $this->flowCookie($flow, 600);
        return $this->response->redirect($url);
    }

    public function callback()
    {
        $flowToken = (string) ($this->request->getCookie(self::FLOW_COOKIE) ?? '');
        $this->flowCookie('', -1); // one use, whatever happens next
        $flow = $flowToken !== '' ? (new JwtService())->decodeStep($flowToken, 'wsc') : null;
        $from = self::safePath((string) ($flow['from'] ?? ''));

        if (! $flow || ! hash_equals((string) $flow['state'], (string) $this->request->getGet('state'))) {
            return $this->toLogin(['wsc_error' => 'This sign-in link has expired or was not started here. Please try again.']);
        }
        if (($err = (string) $this->request->getGet('error')) !== '') {
            // prompt=none without an admin session: fall back to the sign-in page quietly.
            if (! empty($flow['silent'])) return $this->toLogin(['from' => $from]);
            return $this->toLogin(['wsc_error' => $err === 'access_denied' ? 'Sign-in was cancelled.' : 'WorkSuite Cloud could not sign you in.', 'from' => $from]);
        }
        $code = (string) $this->request->getGet('code');
        if ($code === '') return $this->toLogin(['wsc_error' => 'WorkSuite Cloud could not sign you in.']);

        try {
            $claims = $this->oidc()->exchange($code, (string) $flow['verifier'], (string) $flow['nonce']);
        } catch (RuntimeException $e) {
            return $this->toLogin(['wsc_error' => $e->getMessage()]);
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ($claims['email_verified'] ?? false) !== true || ($claims['wsc_staff'] ?? false) !== true) {
            return $this->toLogin(['wsc_error' => 'This WorkSuite Cloud account is not a staff account.']);
        }

        $users = new UserModel();
        $db    = \Config\Database::connect();
        $col   = 'LOWER(' . $db->protectIdentifiers('users.email', true) . ') =';
        $row   = $users->select('users.*, roles.name AS role')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->where($col, $email)->where('users.status', 'active')
            ->whereIn('roles.name', self::ADMIN_ROLES)->first();
        if (! $row) {
            return $this->toLogin(['wsc_error' => "No AI Studio admin account for {$email}"]);
        }
        // The refresh endpoint refuses accounts with enforced but unset 2FA; say so now instead.
        if (! empty($row['two_factor_required']) && empty($row['totp_enabled_at'])) {
            return $this->toLogin(['wsc_error' => 'Your account needs two-factor authentication. Sign in with your password once to set it up.']);
        }

        $id = (int) $row['id'];
        $users->update($id, ['last_login_at' => date('Y-m-d H:i:s')]);
        $jwt = new JwtService();
        $this->refreshCookie($jwt->generateRefreshToken($id, (int) ($row['token_version'] ?? 0)), $jwt->refreshTtl());
        return $this->toLogin(['wsc' => 'ok', 'from' => $from]);
    }

    private function toLogin(array $q)
    {
        $q = array_filter($q, static fn ($v) => $v !== '' && $v !== '/');
        return $this->response->redirect('/login' . ($q ? '?' . http_build_query($q) : ''));
    }

    /** Only same-site paths, never "//host" or a scheme. */
    public static function safePath(string $p): string
    {
        return (str_starts_with($p, '/') && ! str_starts_with($p, '//') && ! str_contains($p, '\\')) ? $p : '/';
    }

    private function flowCookie(string $value, int $maxAge): void
    {
        $this->response->setCookie([
            'name' => self::FLOW_COOKIE, 'value' => $value, 'expire' => $maxAge < 0 ? -1 : $maxAge,
            'path' => '/api/auth/wsc', 'prefix' => '', 'domain' => '',
            'secure' => $this->request->isSecure() || ENVIRONMENT === 'production',
            'httponly' => true,
            'samesite' => 'Lax', // must come back on the top-level redirect from account.worksuitecloud.com
        ]);
    }
}
