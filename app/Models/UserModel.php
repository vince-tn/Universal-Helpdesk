<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $allowedFields    = [
        'name', 'email', 'company', 'password_hash', 'role', 'department', 'department_role_id', 'is_active', 'notify_email', 'expires_at',
        'mfa_secret', 'mfa_enabled', 'mfa_recovery', 'mfa_last_step', 'sso_subject',
    ];

    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_AGENT      = 'agent';
    public const ROLE_SUPERVISOR  = 'supervisor';
    public const ROLE_TEAM_LEADER = 'team_leader';
    public const ROLE_REQUESTER   = 'requester';

    public const ROLES = [
        self::ROLE_SUPERADMIN,
        self::ROLE_SUPERVISOR,
        self::ROLE_TEAM_LEADER,
        self::ROLE_AGENT,
        self::ROLE_REQUESTER,
    ];

    public const STAFF_ROLES = [
        self::ROLE_SUPERADMIN,
        self::ROLE_SUPERVISOR,
        self::ROLE_TEAM_LEADER,
        self::ROLE_AGENT,
    ];

    public function supervisors(): array
    {
        return $this->where('role', self::ROLE_SUPERVISOR)->where('is_active', 1)->orderBy('name', 'ASC')->findAll();
    }

    public function leadCandidates(string $department): array
    {
        return $this->whereIn('role', [self::ROLE_TEAM_LEADER, self::ROLE_AGENT])
            ->where('department', $department)
            ->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    // true once a temporary account has passed its end date
    public static function isExpired(?array $user): bool
    {
        $at = (string) ($user['expires_at'] ?? '');

        return $at !== '' && strtotime($at) !== false && strtotime($at) <= time();
    }

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', trim($email))->first();
    }

    public function createAccount(string $name, string $email, string $password, string $role, ?string $department = null): int|string|false
    {
        return $this->insert([
            'name'          => $name,
            'email'         => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'department'    => $department,
            'is_active'     => 1,
        ]);
    }

    public function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, $user['password_hash']);
    }
}
