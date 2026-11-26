<?php

namespace App\Controllers;

use App\Libraries\Activity;
use App\Libraries\PasswordPolicy;
use App\Libraries\SecurityNotice;
use App\Models\UserModel;

// lets each person choose how they hear about their tickets
class AccountController extends BaseController
{
    public function index()
    {
        $user = (new UserModel())->find((int) current_user()['id']);
        $mfa  = new \App\Libraries\Mfa();

        return view('account/index', [
            'account'     => $user,
            'mfaEnabled'  => $mfa->isEnabled($user),
            'mfaRequired' => \App\Libraries\Mfa::requiredFor((string) $user['role'], (string) ($user['email'] ?? '')),
            'mfaCodes'    => $mfa->recoveryLeft($user),
            'pwRules'     => PasswordPolicy::rulesText(),
        ]);
    }

    public function update()
    {
        $id     = (int) current_user()['id'];
        $notify = $this->request->getPost('notify_email') !== null ? 1 : 0;
        $dept   = trim((string) $this->request->getPost('department'));

        $data = ['notify_email' => $notify];

        // staff departments decide ticket access, so only an admin changes them
        if (current_user()['role'] === UserModel::ROLE_REQUESTER && ($dept === '' || in_array($dept, departments(), true))) {
            $data['department'] = $dept === '' ? null : $dept;
        }

        (new UserModel())->update($id, $data);

        $session = session()->get('user');
        $session['department'] = $data['department'] ?? ($session['department'] ?? null);
        session()->set('user', $session);

        return redirect()->to('/account')->with('success', 'Settings saved.');
    }

    public function passwordForm()
    {
        $user = (new UserModel())->find((int) current_user()['id']);

        return view('account/password', [
            'forced'  => (int) ($user['must_change_password'] ?? 0) === 1,
            'pwRules' => PasswordPolicy::rulesText(),
        ]);
    }

    public function changePassword()
    {
        $users = new UserModel();
        $user  = $users->find((int) current_user()['id']);
        $back  = (int) ($user['must_change_password'] ?? 0) === 1 ? '/account/password' : '/account';

        // stops a borrowed session from guessing the current password
        $failKey  = 'pw_change_fail_' . (int) $user['id'];
        $failures = (int) (cache($failKey) ?? 0);

        if ($failures >= 5) {
            return redirect()->to($back)->with('error', 'Too many wrong current passwords. Wait 15 minutes and try again.');
        }

        if (! $users->verifyPassword($user, (string) $this->request->getPost('current_password'))) {
            cache()->save($failKey, $failures + 1, 900);
            Activity::audit('password_change_failed', 'wrong current password');

            return redirect()->to($back)->with('error', 'Your current password is not right.');
        }

        $password = (string) $this->request->getPost('password');
        $error    = PasswordPolicy::check($password, (string) $this->request->getPost('password_confirm'), (string) $user['email'], (string) $user['password_hash']);

        if ($error !== null) {
            return redirect()->to($back)->with('error', $error);
        }

        $users->update((int) $user['id'], [
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ]);

        cache()->delete($failKey);
        session()->regenerate(true);
        Activity::audit('password_changed', 'changed own password');
        SecurityNotice::passwordChanged($user, 'changed in My settings');

        return $back === '/account/password'
            ? redirect()->to('/tickets')->with('success', 'Password changed. You are all set.')
            : redirect()->to('/account')->with('success', 'Password changed.');
    }
}
