<?php

namespace App\Commands;

use App\Libraries\Activity;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// creates a named super admin and locks the shared demo admin
class NamedAdmin extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:admin';
    protected $description = 'Creates a named super admin with a one-time password, and can lock the shared admin account.';
    protected $usage       = 'helpdesk:admin --name "Full Name" --email you@company.com [--expires-days N] [--lock-shared]';

    private const SHARED = 'admin@universalhelpdesk.local';

    public function run(array $params)
    {
        $users = new UserModel();
        $name  = trim((string) CLI::getOption('name'));
        $email = strtolower(trim((string) CLI::getOption('email')));

        if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
            $days     = (int) (CLI::getOption('expires-days') ?? 0);
            $expires  = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
            $existing = $users->findByEmail($email);

            if ($existing !== null) {
                $users->update($existing['id'], ['role' => UserModel::ROLE_SUPERADMIN, 'is_active' => 1, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'expires_at' => $expires, 'must_change_password' => 1]);
            } else {
                $id = $users->createAccount($name, $email, $password, UserModel::ROLE_SUPERADMIN);
                $users->update((int) $id, ['expires_at' => $expires, 'must_change_password' => 1]);
            }

            Activity::audit('admin_created', 'named super admin ' . $email . ($expires ? ' until ' . $expires : ''));
            CLI::write("super admin {$email} ready, one-time password: {$password}" . ($expires ? ", expires {$expires}" : ''), 'green');
        }

        if (CLI::getOption('lock-shared')) {
            $named = $users->where('role', UserModel::ROLE_SUPERADMIN)->where('is_active', 1)->where('email !=', self::SHARED)->countAllResults();

            if ($named === 0) {
                CLI::error('create a named super admin first, the shared one stays open until then');

                return;
            }

            $shared = $users->findByEmail(self::SHARED);

            if ($shared !== null) {
                $users->update($shared['id'], ['is_active' => 0, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
                Activity::audit('admin_locked', 'locked the shared admin account');
                CLI::write('shared admin locked', 'yellow');
            }
        }
    }
}
