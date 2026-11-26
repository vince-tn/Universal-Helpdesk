<?php

namespace App\Controllers;

use App\Libraries\Activity;
use App\Libraries\EmailVerifier;
use App\Libraries\EntraSso;
use App\Libraries\LoginSession;
use App\Libraries\Mfa;
use App\Libraries\PasswordPolicy;
use App\Libraries\PasswordReset;
use App\Libraries\SecurityNotice;
use App\Libraries\SignupPolicy;
use App\Models\UserModel;
use Config\Access;

class AuthController extends BaseController
{

    private const PENDING_KEY = 'pending_signup_email';

    private const RESET_KEY             = 'pwreset';
    private const RESET_OK_KEY          = 'pwreset_ok';
    private const RESET_MAX_IP_REQUESTS = 20;
    private const RESET_MAX_IP_TRIES    = 30;
    private const RESET_SENT            = 'If that email has an account, we sent a code. Check your inbox.';

    private const LOGIN_MAX_FAILURES    = 5;
    private const LOGIN_MAX_IP_FAILURES = 10;
    private const LOGIN_LOCK_SECONDS    = 900;

    protected UserModel $userModel;
    protected EmailVerifier $verifier;
    protected Access $access;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->verifier  = new EmailVerifier();
        $this->access    = config(Access::class);
    }

    public function loginForm()
    {
        return view('auth/login', ['ssoAvailable' => (new EntraSso())->available()]);
    }

    public function login()
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        $lockKey  = 'login_fail_' . md5(strtolower($email));
        $failures = (int) (cache($lockKey) ?? 0);
        $ipKey    = 'login_fail_ip_' . md5((string) $this->request->getIPAddress());
        $ipFails  = (int) (cache($ipKey) ?? 0);

        if ($failures >= self::LOGIN_MAX_FAILURES || $ipFails >= self::LOGIN_MAX_IP_FAILURES) {
            return redirect()->back()->withInput()->with(
                'error',
                'Too many failed attempts. Wait ' . intdiv(self::LOGIN_LOCK_SECONDS, 60)
                    . ' minutes and try again.'
            );
        }

        $user = $this->userModel->findByEmail($email);

        if ($user === null || ! $this->userModel->verifyPassword($user, $password)) {
            cache()->save($lockKey, $failures + 1, self::LOGIN_LOCK_SECONDS);
            cache()->save($ipKey, $ipFails + 1, self::LOGIN_LOCK_SECONDS);

            return redirect()->back()->withInput()->with('error', 'Invalid email or password.');
        }

        if ((int) $user['is_active'] !== 1 || UserModel::isExpired($user)) {
            return redirect()->back()->withInput()->with('error', 'This account has been deactivated or has expired. Contact your administrator.');
        }

        cache()->delete($lockKey);

        // password is right, but enrolled users still owe the second step
        if ((new Mfa())->isEnabled($user)) {
            session()->regenerate(true);
            session()->set('mfa_pending', ['id' => (int) $user['id'], 'at' => time()]);

            return redirect()->to('/login/mfa');
        }

        LoginSession::start($user, LoginSession::METHOD_PASSWORD);

        if (Mfa::requiredFor((string) $user['role'])) {
            return redirect()->to('/account/mfa/setup')->with('error', 'Your role needs two-step sign-in. Set it up to continue.');
        }

        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            return redirect()->to('/account/password')->with('error', 'Choose a new password to continue.');
        }

        return redirect()->to('/tickets')->with('success', 'Welcome back, ' . $user['name'] . '.');
    }

    public function forgotForm()
    {
        return view('auth/forgot');
    }

    public function forgot()
    {
        $email = strtolower(trim((string) $this->request->getPost('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid email address.');
        }

        if ($this->resetIpLimited()) {
            return redirect()->back()->withInput()->with('error', 'Too many requests from your network. Try again in an hour.');
        }

        (new PasswordReset())->request($email, (string) $this->request->getIPAddress());

        session()->remove(self::RESET_OK_KEY);
        session()->set(self::RESET_KEY, ['email' => $email, 'sent_at' => time()]);

        return redirect()->to('/forgot/code')->with('success', self::RESET_SENT);
    }

    public function forgotCodeForm()
    {
        $pending = session()->get(self::RESET_KEY);

        if (! is_array($pending) || ($pending['email'] ?? '') === '') {
            return redirect()->to('/forgot');
        }

        return view('auth/forgot_code', [
            'email'      => $pending['email'],
            'codeLength' => PasswordReset::CODE_LENGTH,
            'ttlMinutes' => intdiv(PasswordReset::TTL_SECONDS, 60),
            'cooldown'   => PasswordReset::cooldownLeft(date('Y-m-d H:i:s', (int) $pending['sent_at']), time()),
        ]);
    }

    public function forgotCode()
    {
        $pending = session()->get(self::RESET_KEY);

        if (! is_array($pending) || ($pending['email'] ?? '') === '') {
            return redirect()->to('/forgot');
        }

        $tryKey = 'pwreset_try_ip_' . md5((string) $this->request->getIPAddress());
        $tries  = (int) (cache($tryKey) ?? 0);

        if ($tries >= self::RESET_MAX_IP_TRIES) {
            return redirect()->back()->with('error', 'Too many tries from your network. Try again in an hour.');
        }

        cache()->save($tryKey, $tries + 1, 3600);

        // one message for every failure so codes do not reveal which emails exist
        if ((new PasswordReset())->verify((string) $pending['email'], $this->postedCode()) !== PasswordReset::OK) {
            return redirect()->back()->with('error', 'That code did not work. Check you used the newest code, or send a new one.');
        }

        session()->remove(self::RESET_KEY);
        session()->set(self::RESET_OK_KEY, ['email' => $pending['email'], 'at' => time()]);

        return redirect()->to('/forgot/new');
    }

    public function forgotResend()
    {
        $pending = session()->get(self::RESET_KEY);

        if (! is_array($pending) || ($pending['email'] ?? '') === '') {
            return redirect()->to('/forgot');
        }

        $wait = PasswordReset::cooldownLeft(date('Y-m-d H:i:s', (int) $pending['sent_at']), time());

        if ($wait > 0) {
            return redirect()->back()->with('error', 'Wait ' . $wait . ' more seconds before asking for another code.');
        }

        if ($this->resetIpLimited()) {
            return redirect()->back()->with('error', 'Too many requests from your network. Try again in an hour.');
        }

        (new PasswordReset())->request((string) $pending['email'], (string) $this->request->getIPAddress());
        session()->set(self::RESET_KEY, ['email' => $pending['email'], 'sent_at' => time()]);

        return redirect()->back()->with('success', self::RESET_SENT);
    }

    public function resetForm()
    {
        if ($this->verifiedReset() === null) {
            return redirect()->to('/forgot')->with('error', 'That reset has timed out. Start again.');
        }

        return view('auth/forgot_new', ['rules' => PasswordPolicy::rulesText()]);
    }

    public function reset()
    {
        $email = $this->verifiedReset();

        if ($email === null) {
            return redirect()->to('/forgot')->with('error', 'That reset has timed out. Start again.');
        }

        $user = $this->userModel->findByEmail($email);

        if (! PasswordReset::eligible($user)) {
            session()->remove(self::RESET_OK_KEY);

            return redirect()->to('/login')->with('error', 'This account has been deactivated or has expired. Contact your administrator.');
        }

        $password = (string) $this->request->getPost('password');
        $error    = PasswordPolicy::check($password, (string) $this->request->getPost('password_confirm'), $email, (string) $user['password_hash']);

        if ($error !== null) {
            return redirect()->back()->with('error', $error);
        }

        $this->userModel->update($user['id'], [
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ]);

        (new PasswordReset())->clear($email);
        session()->remove(self::RESET_OK_KEY);
        cache()->delete('login_fail_' . md5(strtolower($email)));

        Activity::audit('password_reset', 'reset password with an emailed code', null, $user);
        SecurityNotice::passwordChanged($user, 'reset with an emailed code');

        return redirect()->to('/login')->with('success', 'Password changed. Log in with your new password.');
    }

    private function resetIpLimited(): bool
    {
        $key   = 'pwreset_ip_' . md5((string) $this->request->getIPAddress());
        $count = (int) (cache($key) ?? 0);

        if ($count >= self::RESET_MAX_IP_REQUESTS) {
            return true;
        }

        cache()->save($key, $count + 1, 3600);

        return false;
    }

    // email of a reset whose code was confirmed in the last 15 minutes
    private function verifiedReset(): ?string
    {
        $ok = session()->get(self::RESET_OK_KEY);

        if (! is_array($ok) || ($ok['email'] ?? '') === '' || time() - (int) ($ok['at'] ?? 0) > PasswordReset::TTL_SECONDS) {
            return null;
        }

        return (string) $ok['email'];
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/login')->with('success', 'You have been logged out.');
    }

    public function signupForm()
    {
        return view('auth/signup', [
            'domainHint'  => $this->access->domainHint(),
            'domainCheck' => $this->access->domainCheckEnabled(),
        ]);
    }

    public function signup()
    {
        $name     = trim((string) $this->request->getPost('name'));
        $email    = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $confirm  = (string) $this->request->getPost('password_confirm');

        $error = $this->validateDetails($name, $email, $password, $confirm);

        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $issued = $this->verifier->issue($email, [
            'name'          => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        if (! $issued['sent']) {
            return redirect()->back()->withInput()->with('error', $issued['reason']);
        }

        session()->set(self::PENDING_KEY, $email);

        return redirect()->to('/signup/verify')
            ->with('success', 'We sent a ' . $this->verifier->codeLength() . '-digit code to ' . $email . '.');
    }

    public function verifyForm()
    {
        $email = (string) session()->get(self::PENDING_KEY);

        if ($email === '') {
            return redirect()->to('/signup')->with('error', 'Start by entering your details.');
        }

        return view('auth/verify', [
            'email'      => $email,
            'codeLength' => $this->verifier->codeLength(),
            'ttlMinutes' => $this->verifier->ttlMinutes(),
            'cooldown'   => $this->verifier->cooldownRemaining($email),
        ]);
    }

    public function verify()
    {
        $email = (string) session()->get(self::PENDING_KEY);

        if ($email === '') {
            return redirect()->to('/signup')->with('error', 'That signup has expired. Please start again.');
        }

        $result = $this->verifier->verify($email, $this->postedCode());

        if ($result['status'] !== EmailVerifier::OK) {
            return redirect()->back()->with('error', $this->explain($result));
        }

        $payload = $result['payload'];

        if (($payload['name'] ?? '') === '' || ($payload['password_hash'] ?? '') === '') {

            session()->remove(self::PENDING_KEY);

            return redirect()->to('/signup')->with('error', 'We lost track of that signup. Please enter your details again.');
        }

        if ($this->userModel->findByEmail($email) !== null) {
            session()->remove(self::PENDING_KEY);

            return redirect()->to('/login')->with('error', 'An account with that email already exists. Try logging in.');
        }

        $created = $this->userModel->insert([
            'name'          => $payload['name'],
            'email'         => $email,
            'password_hash' => $payload['password_hash'],
            'role'          => UserModel::ROLE_REQUESTER,
            'department'    => null,
            'is_active'     => 1,
        ]);

        if ($created === false) {
            return redirect()->back()->with('error', 'Something went wrong creating your account. Please try again.');
        }

        session()->remove(self::PENDING_KEY);

        log_message('info', 'Verified signup for {email}', ['email' => $email]);

        return redirect()->to('/login')
            ->with('success', 'Email verified and your account is ready. Log in to raise your first ticket.');
    }

    public function resend()
    {
        $email = (string) session()->get(self::PENDING_KEY);

        if ($email === '') {
            return redirect()->to('/signup')->with('error', 'Start by entering your details.');
        }

        $payload = $this->verifier->pendingPayload($email);

        if ($payload === []) {
            session()->remove(self::PENDING_KEY);

            return redirect()->to('/signup')->with('error', 'That signup has expired. Please start again.');
        }

        $issued = $this->verifier->issue($email, $payload);

        return redirect()->back()->with(
            $issued['sent'] ? 'success' : 'error',
            $issued['sent'] ? 'A new code is on its way to ' . $email . '.' : $issued['reason']
        );
    }

    private function postedCode(): string
    {
        $posted = $this->request->getPost('code');

        if (is_array($posted)) {
            return implode('', array_map(static fn ($d) => (string) $d, $posted));
        }

        return (string) $posted;
    }

    private function validateDetails(string $name, string $email, string $password, string $confirm): ?string
    {
        return match (true) {
            $name === '' || $email === '' || $password === ''
                => 'Please fill in all fields.',
            ! filter_var($email, FILTER_VALIDATE_EMAIL)
                => 'Please enter a valid email address.',
            ! (new SignupPolicy($this->access))->allows($email)
                => 'Use your work email. Self-service signup is limited to ' . $this->access->domainHint()
                    . '. If you are with a partner agency or an external contractor, ask IT to give your address access.',
            strlen($password) < 8
                => 'Password must be at least 8 characters.',
            $password !== $confirm
                => 'Passwords do not match.',
            $this->userModel->findByEmail($email) !== null
                => 'An account with that email already exists.',
            default => null,
        };
    }

    private function explain(array $result): string
    {
        return match ($result['status']) {
            EmailVerifier::EXPIRED, EmailVerifier::NO_CODE
                => 'That code has expired. Use "Send a new code" below.',
            EmailVerifier::TOO_MANY
                => 'Too many wrong attempts, so that code has been cancelled. Send a new one to try again.',
            default
                => 'That code is not right. ' . $result['remaining'] . ' attempt'
                    . ($result['remaining'] === 1 ? '' : 's') . ' left.',
        };
    }
}
