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
        'name', 'email', 'company', 'password_hash', 'role', 'department', 'department_role_id',
        'can_edit_knowledge', 'is_active',
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
