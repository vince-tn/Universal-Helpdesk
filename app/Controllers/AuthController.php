<?php

namespace App\Controllers;

use App\Libraries\EmailVerifier;
use App\Models\UserModel;
use Config\Access;

class AuthController extends BaseController
{

    private const PENDING_KEY = 'pending_signup_email';

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
        return view('auth/login');
    }

    public function login()
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        $user = $this->userModel->findByEmail($email);

        if ($user === null || ! $this->userModel->verifyPassword($user, $password)) {
            return redirect()->back()->withInput()->with('error', 'Invalid email or password.');
        }

        if ((int) $user['is_active'] !== 1) {
            return redirect()->back()->withInput()->with('error', 'This account has been deactivated. Contact your administrator.');
        }

        session()->set([
            'isLoggedIn' => true,
            'user' => [
                'id'         => $user['id'],
                'name'       => $user['name'],
                'email'      => $user['email'],
                'role'       => $user['role'],
                'department' => $user['department'],
            ],
        ]);

        return redirect()->to('/tickets')->with('success', 'Welcome back, ' . $user['name'] . '.');
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
            ! $this->access->allows($email)
                => 'Use your work email. Self-service signup is limited to ' . $this->access->domainHint()
                    . '. If you are an external contractor, ask IT to create the account for you.',
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
