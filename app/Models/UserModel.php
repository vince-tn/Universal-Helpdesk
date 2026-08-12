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
        'name', 'email', 'password_hash', 'role', 'department', 'department_role_id', 'is_active',
    ];

    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_AGENT      = 'agent';
    public const ROLE_REQUESTER  = 'requester';

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

    public function agentsInDepartment(string $department): array
    {
        return $this->where('role', self::ROLE_AGENT)
            ->where('department', $department)
            ->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
