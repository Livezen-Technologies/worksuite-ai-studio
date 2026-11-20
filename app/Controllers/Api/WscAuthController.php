<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\JwtService;
use App\Services\WscAccountSync;
use App\Services\WscOidcService;
use RuntimeException;

/**
 * "Sign in with WorkSuite Cloud" for AI Studio admins (staff SSO contract, part 3).
 *
 *   GET /api/auth/wsc/config              -> { enabled }            (the SPA hides the button when off)
 *   GET /api/auth/wsc/start?from=&silent=1 -> 302 to OneAuth, flow state in a short-lived signed cookie
 *   GET /api/auth/wsc/callback?code&state -> 302 to /login?wsc=ok (refresh cookie set) or /login?wsc_error=…
 *   POST /api/auth/wsc/provision           -> 204; a signed provisioning event (application/jwt) from WorkSuite Cloud
 *
 * Part 2 (admin account sync): access to AI Studio is granted in Cloud Admin. The id_token must carry
 * `wsc_app_role` (an AI Studio staff role code). The user is found by `wsc_sub`, else by email (any
 * letter case) and linked, else created; the role is set from the claim. See WscAccountSync.
 */
class WscAuthController extends BaseApiController
{
    use Concerns\IssuesSessions;

    public const FLOW_COOKIE = 'aistudio_wsc';

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
            // access_denied means no grant for AI Studio in Cloud Admin: say so, silent or not.
            if ($err === 'access_denied') {
                return $this->toLogin(['wsc_error' => "You don't have access to AI Studio. Ask a WorkSuite Cloud administrator.", 'wsc_denied' => '1', 'from' => $from]);
            }
            if (! empty($flow['silent'])) return $this->toLogin(['from' => $from]);
            return $this->toLogin(['wsc_error' => 'WorkSuite Cloud could not sign you in.', 'from' => $from]);
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

        $role = (string) ($claims['wsc_app_role'] ?? '');
        if (! WscAccountSync::acceptsRole($role)) {
            return $this->toLogin(['wsc_error' => "You don't have access to AI Studio. Ask a WorkSuite Cloud administrator.", 'wsc_denied' => '1']);
        }
        try {
            $id = (new WscAccountSync())->upsert((string) ($claims['sub'] ?? ''), $email, (string) ($claims['name'] ?? ''), $role);
        } catch (RuntimeException $e) {
            return $this->toLogin(['wsc_error' => $e->getMessage()]);
        }
        $users = new UserModel();
        $row   = $users->find($id);
        if (! $row || $row['status'] !== 'active') {
            return $this->toLogin(['wsc_error' => 'Your AI Studio account is suspended. Ask an AI Studio administrator.']);
        }
        // The refresh endpoint refuses accounts with enforced but unset 2FA; say so now instead.
        if (! empty($row['two_factor_required']) && empty($row['totp_enabled_at'])) {
            return $this->toLogin(['wsc_error' => 'Your account needs two-factor authentication. Sign in with your password once to set it up.']);
        }

        $users->update($id, ['last_login_at' => date('Y-m-d H:i:s')]);
        $jwt = new JwtService();
        $this->refreshCookie($jwt->generateRefreshToken($id, (int) ($row['token_version'] ?? 0)), $jwt->refreshTtl());
        return $this->toLogin(['wsc' => 'ok', 'from' => $from]);
    }

    /**
     * Provisioning push from WorkSuite Cloud. Content-Type application/jwt; body is a compact RS256 JWS
     * signed with the OIDC key. 204 on success (including "nobody to disable"), 400 malformed, 401 bad token.
     */
    public function provision()
    {
        $oidc = $this->oidc();
        if (! $oidc->enabled()) return $this->response->setStatusCode(404);
        $type = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($type !== 'application/jwt') return $this->failure('Content-Type must be application/jwt.', 400);
        $jwt = trim((string) $this->request->getBody());
        if ($jwt === '' || strlen($jwt) > 16384 || substr_count($jwt, '.') !== 2) return $this->failure('Malformed token.', 400);

        try {
            $c = $oidc->verifyProvisionToken($jwt);
        } catch (RuntimeException $e) {
            return $this->failure('Invalid provisioning token: ' . $e->getMessage(), 401);
        }
        $sync = new WscAccountSync();
        if (! $sync->claimJti((string) $c['jti'], (int) $c['exp'])) return $this->failure('Token already used.', 401);

        $sub = (string) $c['sub'];
        $email = strtolower(trim((string) ($c['email'] ?? '')));
        try {
            if ($c['event'] === 'disable') {
                $sync->disable($sub, $email);
            } else {
                if (! WscAccountSync::acceptsRole((string) ($c['role'] ?? ''))) return $this->failure('Unknown role.', 400);
                $sync->upsert($sub, $email, (string) ($c['name'] ?? ''), (string) $c['role']);
            }
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 400);
        }
        return $this->response->setStatusCode(204);
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
