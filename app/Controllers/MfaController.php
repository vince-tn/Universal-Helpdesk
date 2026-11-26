<?php

namespace App\Controllers;

use App\Libraries\Activity;
use App\Libraries\LoginSession;
use App\Libraries\Mfa;
use App\Libraries\Totp;
use App\Models\UserModel;

// second sign in step, plus setting it up and turning it off in my settings
class MfaController extends BaseController
{
    private const PENDING_TTL = 300;
    private const SETUP_TTL   = 900;

    protected Mfa $mfa;
    protected UserModel $users;

    public function __construct()
    {
        $this->mfa   = new Mfa();
        $this->users = new UserModel();
    }

    public function challengeForm()
    {
        if ($this->pendingUser() === null) {
            return redirect()->to('/login')->with('error', 'Your sign in timed out. Log in again.');
        }

        return view('auth/mfa');
    }

    public function challenge()
    {
        $user = $this->pendingUser();

        if ($user === null) {
            session()->remove('mfa_pending');

            return redirect()->to('/login')->with('error', 'Your sign in timed out. Log in again.');
        }

        $id = (int) $user['id'];

        if ($this->mfa->locked($id)) {
            return redirect()->to('/login/mfa')->with('error', 'Too many wrong codes. Wait 10 minutes and try again.');
        }

        $code     = trim((string) $this->request->getPost('code'));
        $recovery = null;
        $ok       = $this->mfa->looksLikeTotp($code)
            ? $this->mfa->verifyTotp($user, $code)
            : ($recovery = $this->mfa->useRecoveryCode($user, $code)) !== null;

        if (! $ok) {
            $left = $this->mfa->recordFailure($id);
            Activity::audit('mfa_failed', 'wrong sign in code for ' . $user['email'], null, $user);

            return redirect()->to('/login/mfa')->with('error', $left > 0
                ? 'That code is not right. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.'
                : 'Too many wrong codes. Wait 10 minutes and try again.');
        }

        $this->mfa->clearFailures($id);
        LoginSession::start($user, LoginSession::METHOD_MFA);

        if ($recovery !== null) {
            Activity::audit('mfa_recovery_used', $recovery . ' recovery codes left for ' . $user['email']);

            return redirect()->to('/account')->with('error', 'You signed in with a recovery code. ' . $recovery
                . ' left. Make new ones below if you are running low.');
        }

        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            return redirect()->to('/account/password')->with('error', 'Choose a new password to continue.');
        }

        return redirect()->to('/tickets')->with('success', 'Welcome back, ' . $user['name'] . '.');
    }

    public function setupForm()
    {
        $user = $this->users->find((int) current_user()['id']);

        if ($this->mfa->isEnabled($user)) {
            return redirect()->to('/account')->with('success', 'Two-step sign-in is already on.');
        }

        $data = ['required' => Mfa::requiredFor((string) $user['role'], (string) ($user['email'] ?? '')), 'unavailable' => ! $this->mfa->canStoreSecrets()];

        if ($data['unavailable']) {
            log_message('critical', 'mfa setup blocked: set encryption.key or MFA_KEY in the server env');

            return view('account/mfa_setup', $data);
        }

        $secret = $this->setupSecret(true);

        return view('account/mfa_setup', $data + [
            'secret'  => Mfa::groupSecret($secret),
            'otpauth' => Totp::uri($secret, (string) $user['email'], brand_name()),
        ]);
    }

    public function setup()
    {
        $user = $this->users->find((int) current_user()['id']);
        $id   = (int) $user['id'];

        if ($this->mfa->isEnabled($user)) {
            return redirect()->to('/account');
        }

        if (! $this->mfa->canStoreSecrets()) {
            return redirect()->to('/account/mfa/setup');
        }

        if ($this->mfa->locked($id)) {
            return redirect()->to('/account/mfa/setup')->with('error', 'Too many wrong codes. Wait 10 minutes and try again.');
        }

        $secret = $this->setupSecret(false);
        $key    = $secret === null ? null : Totp::base32Decode($secret);

        if ($key === null) {
            return redirect()->to('/account/mfa/setup')->with('error', 'That setup expired. Scan the new code and try again.');
        }

        $step = Totp::verify($key, (string) $this->request->getPost('code'));

        if ($step === null) {
            $this->mfa->recordFailure($id);
            Activity::audit('mfa_failed', 'wrong code while setting up two-step sign-in');

            return redirect()->to('/account/mfa/setup')->with('error', 'That code is not right. Check the time on your phone and try the newest code.');
        }

        try {
            $codes = $this->mfa->enable($id, $secret, $step);
        } catch (\Throwable $e) {
            log_message('error', 'mfa enable failed: {m}', ['m' => $e->getMessage()]);

            return redirect()->to('/account/mfa/setup')->with('error', 'Could not save two-step sign-in. Ask IT to check the server key.');
        }

        $this->mfa->clearFailures($id);
        session()->remove('mfa_setup');
        session()->set('auth_mfa', true);
        Activity::audit('mfa_enrolled', 'turned on two-step sign-in');

        return $this->showCodes($codes, 'Two-step sign-in is on');
    }

    public function disable()
    {
        $user = $this->users->find((int) current_user()['id']);

        if (! $this->mfa->isEnabled($user)) {
            return redirect()->to('/account');
        }

        if (Mfa::requiredFor((string) $user['role'], (string) ($user['email'] ?? ''))) {
            return redirect()->to('/account')->with('error', 'Your role must keep two-step sign-in on. If you lose your phone, a super admin can reset it.');
        }

        if (! $this->confirmCode($user)) {
            return redirect()->to('/account')->with('error', 'That code is not right, so two-step sign-in is still on.');
        }

        $this->mfa->disable((int) $user['id']);
        Activity::audit('mfa_disabled', 'turned off two-step sign-in');

        return redirect()->to('/account')->with('success', 'Two-step sign-in is off.');
    }

    public function regenerate()
    {
        $user = $this->users->find((int) current_user()['id']);

        if (! $this->mfa->isEnabled($user)) {
            return redirect()->to('/account');
        }

        if (! $this->confirmCode($user)) {
            return redirect()->to('/account')->with('error', 'That code is not right, so your recovery codes did not change.');
        }

        $codes = $this->mfa->regenerateRecovery((int) $user['id']);
        Activity::audit('mfa_recovery_regenerated', 'made new recovery codes');

        return $this->showCodes($codes, 'New recovery codes');
    }

    private function confirmCode(array $user): bool
    {
        $id = (int) $user['id'];

        if ($this->mfa->locked($id)) {
            return false;
        }

        if ($this->mfa->verifyTotp($user, (string) $this->request->getPost('code'))) {
            $this->mfa->clearFailures($id);

            return true;
        }

        $this->mfa->recordFailure($id);
        Activity::audit('mfa_failed', 'wrong code in my settings');

        return false;
    }

    private function showCodes(array $codes, string $title)
    {
        $this->response->setHeader('Cache-Control', 'no-store, max-age=0');

        return view('account/mfa_codes', ['codes' => $codes, 'title' => $title]);
    }

    private function pendingUser(): ?array
    {
        $pending = session()->get('mfa_pending');

        if (! is_array($pending) || (time() - (int) ($pending['at'] ?? 0)) > self::PENDING_TTL) {
            return null;
        }

        $user = $this->users->find((int) ($pending['id'] ?? 0));

        if ($user === null || (int) $user['is_active'] !== 1 || ! $this->mfa->isEnabled($user)) {
            return null;
        }

        return $user;
    }

    // keeps one encrypted secret per setup so a reload shows the same qr code
    private function setupSecret(bool $create): ?string
    {
        $saved = session()->get('mfa_setup');

        if (is_array($saved) && (time() - (int) ($saved['at'] ?? 0)) <= self::SETUP_TTL) {
            $secret = $this->mfa->decryptSecret((string) ($saved['secret'] ?? ''));

            if ($secret !== null) {
                return $secret;
            }
        }

        if (! $create) {
            return null;
        }

        $secret = Totp::generateSecret();
        session()->set('mfa_setup', ['secret' => $this->mfa->encryptSecret($secret), 'at' => time()]);

        return $secret;
    }
}
