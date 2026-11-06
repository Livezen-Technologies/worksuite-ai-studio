<?php

namespace App\Controllers\Api;

use App\Libraries\PasswordPolicy;
use App\Models\UserModel;
use App\Services\JwtService;
use App\Services\TotpService;
use App\Services\WebAuthnService;
use RuntimeException;

/**
 * The signed-in user's own sign-in methods (Account → Security). Every route needs a Bearer token.
 *
 *   GET    /api/account/security                     -> { two_factor_enabled, two_factor_required, recovery_codes_left, passkeys[] }
 *   POST   /api/account/security/totp/setup          -> { secret, uri, setup_token }
 *   POST   /api/account/security/totp/enable         { setup_token, code } -> { recovery_codes }
 *   POST   /api/account/security/totp/disable        { password }
 *   POST   /api/account/security/recovery-codes      { password } -> { recovery_codes }
 *   POST   /api/account/security/password            { current_password, new_password }
 *                                                     -> session like a login (user, access_token, refresh_token,
 *                                                        token_type, expires_in + refresh cookie); other devices are signed out
 *   POST   /api/account/security/passkeys/options    -> { options, challenge_token }
 *   POST   /api/account/security/passkeys            { challenge_token, credential, name }
 *   PUT    /api/account/security/passkeys/:id        { name }
 *   DELETE /api/account/security/passkeys/:id
 */
class SecurityController extends BaseApiController
{
    use Concerns\IssuesSessions;

    public function index()
    {
        $user = (new UserModel())->find($this->userId());
        if (! $user) return $this->failure('User not found.', 404);

        $passkeys = \Config\Database::connect()->table('user_passkeys')
            ->select('id, name, transports, created_at, last_used_at')
            ->where('user_id', $this->userId())->orderBy('id')->get()->getResultArray();

        return $this->ok([
            'two_factor_enabled'  => ! empty($user['totp_enabled_at']),
            'two_factor_since'    => $user['totp_enabled_at'],
            'two_factor_required' => (bool) $user['two_factor_required'],
            'recovery_codes_left' => (new TotpService())->remainingRecoveryCodes($this->userId()),
            'passkeys'            => $passkeys,
        ]);
    }

    public function totpSetup()
    {
        $user   = (new UserModel())->find($this->userId());
        $totp   = new TotpService();
        $secret = $totp->generateSecret();
        return $this->ok([
            'secret'      => $secret,
            'uri'         => $totp->uri($secret, $user['email']),
            'setup_token' => (new JwtService())->generateStepToken('totp_setup', ['sub' => $this->userId(), 'secret' => $secret], 900),
        ]);
    }

    public function totpEnable()
    {
        $in    = $this->input();
        $setup = (new JwtService())->decodeStep((string) ($in['setup_token'] ?? ''), 'totp_setup');
        if (! $setup || (int) $setup['sub'] !== $this->userId()) {
            return $this->failure('Setup expired. Start again.', 422);
        }
        $totp = new TotpService();
        $step = $totp->verify((string) $setup['secret'], (string) ($in['code'] ?? ''));
        if ($step === null) {
            return $this->failure('That code is not valid. Check the time on your phone and try again.', 422, ['code' => 'Invalid code.']);
        }
        return $this->ok(['recovery_codes' => $totp->enable($this->userId(), (string) $setup['secret'], $step)], 'Two-factor authentication is on.');
    }

    public function totpDisable()
    {
        if ($r = $this->checkPassword()) return $r;
        $user = (new UserModel())->find($this->userId());
        if (! empty($user['two_factor_required'])) {
            return $this->failure('An administrator requires two-factor authentication on your account.', 403);
        }
        (new TotpService())->disable($this->userId());
        return $this->ok(null, 'Two-factor authentication is off.');
    }

    public function recoveryCodes()
    {
        if ($r = $this->checkPassword()) return $r;
        $user = (new UserModel())->find($this->userId());
        if (empty($user['totp_enabled_at'])) {
            return $this->failure('Turn on two-factor authentication first.', 422);
        }
        return $this->ok(['recovery_codes' => (new TotpService())->newRecoveryCodes($this->userId())], 'New recovery codes created.');
    }

    public function passkeyOptions()
    {
        try {
            $wa = new WebAuthnService($this->request);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 400);
        }
        $user      = (new UserModel())->find($this->userId());
        $challenge = WebAuthnService::challenge();
        $existing  = \Config\Database::connect()->table('user_passkeys')->select('credential_id')
            ->where('user_id', $this->userId())->get()->getResultArray();

        return $this->ok([
            'options' => [
                'challenge' => $challenge,
                'rp'        => ['id' => $wa->rpId(), 'name' => WebAuthnService::RP_NAME],
                'user'      => [
                    'id'          => WebAuthnService::b64u((string) $user['id']),
                    'name'        => $user['email'],
                    'displayName' => $user['name'],
                ],
                'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
                'timeout'                => 120000,
                'attestation'            => 'none',
                'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
                'excludeCredentials'     => array_map(static fn ($r) => ['type' => 'public-key', 'id' => $r['credential_id']], $existing),
            ],
            'challenge_token' => (new JwtService())->generateStepToken('webauthn_create', ['sub' => $this->userId(), 'challenge' => $challenge], 180),
        ]);
    }

    public function passkeyCreate()
    {
        $in      = $this->input();
        $payload = (new JwtService())->decodeStep((string) ($in['challenge_token'] ?? ''), 'webauthn_create');
        if (! $payload || (int) $payload['sub'] !== $this->userId()) {
            return $this->failure('The passkey request expired. Please try again.', 422);
        }
        try {
            [$credId, $pem, $alg, $count, $transports] = (new WebAuthnService($this->request))
                ->verifyRegistration(is_array($in['credential'] ?? null) ? $in['credential'] : [], (string) $payload['challenge']);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 422);
        }

        $db = \Config\Database::connect();
        if ($db->table('user_passkeys')->where('credential_id', $credId)->countAllResults() > 0) {
            return $this->failure('This passkey is already registered.', 422);
        }
        $name = trim((string) ($in['name'] ?? '')) ?: 'Passkey';
        $db->table('user_passkeys')->insert([
            'user_id'       => $this->userId(),
            'name'          => mb_substr($name, 0, 120),
            'credential_id' => $credId,
            'public_key'    => $pem,
            'alg'           => $alg,
            'sign_count'    => $count,
            'transports'    => $transports ?: null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['id' => (int) $db->insertID()], 'Passkey added.', 201);
    }

    public function passkeyRename($id = null)
    {
        $name = trim((string) ($this->input()['name'] ?? ''));
        if ($name === '') return $this->failure('Give the passkey a name.', 422, ['name' => 'Required.']);
        $db = \Config\Database::connect();
        $db->table('user_passkeys')->where('id', (int) $id)->where('user_id', $this->userId())->update(['name' => mb_substr($name, 0, 120)]);
        return $this->ok(null, 'Passkey renamed.');
    }

    public function passkeyDelete($id = null)
    {
        $db = \Config\Database::connect();
        $db->table('user_passkeys')->where('id', (int) $id)->where('user_id', $this->userId())->delete();
        return $db->affectedRows() ? $this->ok(null, 'Passkey removed.') : $this->failure('Passkey not found.', 404);
    }

    /** Change your own password. Ends every other session (token_version bump) and returns a fresh one. */
    public function changePassword()
    {
        if ($r = $this->passwordThrottle()) return $r;
        $in      = $this->input();
        $current = (string) ($in['current_password'] ?? '');
        $new     = (string) ($in['new_password'] ?? '');
        $users   = new UserModel();
        $user    = $users->find($this->userId());
        if (! $user) return $this->failure('User not found.', 404);
        if ($current === '' || ! password_verify($current, $user['password_hash'])) {
            return $this->failure('Your current password is not correct.', 422, ['current_password' => 'Incorrect password.']);
        }
        if ($why = PasswordPolicy::problem($new, (string) $user['email'])) {
            return $this->failure($why, 422, ['new_password' => $why]);
        }
        if (password_verify($new, $user['password_hash'])) {
            $why = 'Choose a password different from your current one.';
            return $this->failure($why, 422, ['new_password' => $why]);
        }
        $users->update((int) $user['id'], ['password_hash' => $users->hash($new)]);
        $users->revokeTokens((int) $user['id']);
        return $this->session((int) $user['id'], 'Password changed. Other devices have been signed out.');
    }

    /** 5 password checks per user per minute (shared by every endpoint that re-checks the password). */
    private function passwordThrottle()
    {
        return service('throttler')->check('account-password-' . $this->userId(), 5, MINUTE)
            ? null
            : $this->failure('Too many attempts. Wait a minute and try again.', 429);
    }

    /** Sensitive changes re-check the account password. */
    private function checkPassword()
    {
        if ($r = $this->passwordThrottle()) return $r;
        $user = (new UserModel())->find($this->userId());
        if (! $user || ! password_verify((string) ($this->input()['password'] ?? ''), $user['password_hash'])) {
            return $this->failure('Your password is not correct.', 422, ['password' => 'Incorrect password.']);
        }
        return null;
    }
}
