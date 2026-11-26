<?php

namespace App\Filters;

use App\Libraries\LoginSession;
use App\Libraries\Mfa;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('isLoggedIn')) {
            return redirect()->to('/login')->with('error', 'Please log in to continue.');
        }

        $id  = (int) ($session->get('user')['id'] ?? 0);
        $row = $id > 0 ? (new UserModel())->find($id) : null;

        if ($row === null || (int) $row['is_active'] !== 1 || UserModel::isExpired($row)) {
            $session->remove(['isLoggedIn', 'user']);

            return redirect()->to('/login')->with('error', 'Your account is no longer active. Contact your administrator.');
        }

        $fresh = [
            'id'                 => $row['id'],
            'name'               => $row['name'],
            'email'              => $row['email'],
            'role'               => $row['role'],
            'department'         => $row['department'],
            'can_edit_knowledge' => (int) ($row['can_edit_knowledge'] ?? 0),
        ];

        if ($fresh != $session->get('user')) {
            $session->set('user', $fresh);
        }

        $role     = $fresh['role'];
        $enrolled = (int) ($row['mfa_enabled'] ?? 0) === 1;

        // enrolled users must have passed the second step in this session
        if ($enrolled && ! $session->get('auth_mfa')) {
            $session->remove(['isLoggedIn', 'user', 'auth_mfa', 'auth_method']);

            return redirect()->to('/login')->with('error', 'Please log in again to confirm your two-step sign-in.');
        }

        // roles that need mfa finish setup before anything else, sso already counts
        if (! $enrolled && Mfa::requiredFor($role) && (new Mfa())->canStoreSecrets() && $session->get('auth_method') !== LoginSession::METHOD_SSO) {
            $path = trim($request->getPath(), '/');

            if (! in_array($path, ['account/mfa/setup', 'logout'], true)) {
                return redirect()->to('/account/mfa/setup')->with('error', 'Your role needs two-step sign-in. Set it up to continue.');
            }
        }

        if ($arguments !== null && ! in_array($role, $arguments, true)) {
            return redirect()->to('/tickets')->with('error', "You don't have access to that page.");
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
