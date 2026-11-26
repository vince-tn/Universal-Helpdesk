<?php

namespace App\Libraries;

// marks a session as logged in once every sign in step is done
final class LoginSession
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_MFA      = 'password_mfa';
    public const METHOD_SSO      = 'sso';

    public static function start(array $user, string $method): void
    {
        $session = session();
        $session->regenerate(true);
        $session->remove(['mfa_pending', 'mfa_setup', 'sso_flow']);

        $session->set([
            'isLoggedIn'  => true,
            'auth_method' => $method,
            'auth_mfa'    => $method !== self::METHOD_PASSWORD,
            'user'        => [
                'id'         => $user['id'],
                'name'       => $user['name'],
                'email'      => $user['email'],
                'role'       => $user['role'],
                'department' => $user['department'],
                'can_edit_knowledge' => (int) ($user['can_edit_knowledge'] ?? 0),
            ],
        ]);
    }
}
