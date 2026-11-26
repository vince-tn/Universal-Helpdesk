<?php

namespace App\Controllers;

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
}
