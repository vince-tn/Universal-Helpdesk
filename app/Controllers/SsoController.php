<?php

namespace App\Controllers;

use App\Libraries\Activity;
use App\Libraries\EntraSso;
use App\Libraries\LoginSession;
use App\Models\UserModel;

// sign in with microsoft entra id
class SsoController extends BaseController
{
    public function login()
    {
        $sso = new EntraSso();

        if (! $sso->available()) {
            return redirect()->to('/login')->with('error', 'Microsoft sign in is not available here. Use your email and password.');
        }

        return redirect()->to($sso->begin());
    }

    public function callback()
    {
        $sso = new EntraSso();

        if (! $sso->configured()) {
            return redirect()->to('/login')->with('error', 'Microsoft sign in is not set up.');
        }

        try {
            $claims = $sso->finish($this->request->getGet() ?? []);
        } catch (\RuntimeException $e) {
            return redirect()->to('/login')->with('error', $e->getMessage());
        }

        $email   = $sso->emailFrom($claims);
        $subject = $sso->subjectOf($claims);

        if ($email === null || str_ends_with($subject, ':')) {
            return redirect()->to('/login')->with('error', 'Your Microsoft account did not share an email address. Ask IT to check the single sign-on setup.');
        }

        $users = new UserModel();
        $user  = $users->findByEmail($email);

        if ($user === null) {
            if (! $sso->domainAllowed($email)) {
                return redirect()->to('/login')->with('error', 'There is no account for ' . $email . '. Ask IT to give your address access.');
            }

            $id = $users->insert([
                'name'          => mb_substr(trim((string) ($claims['name'] ?? '')) ?: strstr($email, '@', true), 0, 150),
                'email'         => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'role'          => UserModel::ROLE_REQUESTER,
                'department'    => null,
                'is_active'     => 1,
                'sso_subject'   => $subject,
            ]);

            $user = $id === false ? null : $users->find($id);

            if ($user === null) {
                return redirect()->to('/login')->with('error', 'Something went wrong creating your account. Please try again.');
            }

            Activity::audit('sso_user_created', 'requester account created on first microsoft sign in for ' . $email, null, $user);
        }

        if ((int) $user['is_active'] !== 1) {
            return redirect()->to('/login')->with('error', 'This account has been deactivated. Contact your administrator.');
        }

        // outside a fixed tenant, only trust addresses on our own domains
        if (! $sso->tenantIsGuid() && ! $sso->domainAllowed($email)) {
            return redirect()->to('/login')->with('error', 'Microsoft sign in is limited to company addresses. Use your email and password.');
        }

        $linked = (string) ($user['sso_subject'] ?? '');

        if ($linked !== '' && ! hash_equals($linked, $subject)) {
            Activity::audit('sso_subject_mismatch', 'microsoft account did not match the one linked to ' . $email, null, $user);

            return redirect()->to('/login')->with('error', 'That Microsoft account is not the one linked to ' . $email . '. Contact your administrator.');
        }

        if ($linked === '') {
            $users->update((int) $user['id'], ['sso_subject' => $subject]);
        }

        LoginSession::start($user, LoginSession::METHOD_SSO);
        log_message('info', 'sso sign in for {email}', ['email' => $email]);

        return redirect()->to('/tickets')->with('success', 'Welcome back, ' . $user['name'] . '.');
    }
}
