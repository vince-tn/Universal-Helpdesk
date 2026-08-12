<?php

namespace App\Models;

use CodeIgniter\Model;

class DepartmentModel extends Model
{
    protected $table         = 'departments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'is_active', 'sort_order'];

    public function active(): array
    {
        return $this->where('is_active', 1)->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
    }

    public function allOrdered(): array
    {
        return $this->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
    }

    public function names(): array
    {
        return array_column($this->active(), 'name');
    }

    public function findByName(string $name): ?array
    {
        return $this->where('name', trim($name))->first();
    }

    public function withRoles(): array
    {
        $departments = $this->allOrdered();

        if ($departments === []) {
            return [];
        }

        $roles = (new DepartmentRoleModel())
            ->whereIn('department_id', array_column($departments, 'id'))
            ->orderBy('name', 'ASC')
            ->findAll();

        $byDepartment = [];
        foreach ($roles as $role) {
            $byDepartment[(int) $role['department_id']][] = $role;
        }

        foreach ($departments as &$department) {
            $department['roles'] = $byDepartment[(int) $department['id']] ?? [];
        }

        return $departments;
    }

    public function agentCount(string $name): int
    {
        return (new UserModel())
            ->where('department', $name)
            ->where('role', UserModel::ROLE_AGENT)
            ->countAllResults();
    }
}
