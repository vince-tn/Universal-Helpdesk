<?php

namespace App\Models;

use CodeIgniter\Model;

class DepartmentModel extends Model
{
    protected $table         = 'departments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'handles', 'allow_cloud_ai', 'supervisor_id', 'team_leader_id', 'is_active', 'sort_order', 'is_confidential'];

    public const HANDLES_MAX = 150;

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

    public function idFor(string $ticketDepartment): ?int
    {
        $name = resolve_base_department($ticketDepartment);
        $row  = $name === '' ? null : $this->findByName($name);

        return $row === null ? null : (int) $row['id'];
    }

    public function allowsCloud(string $ticketDepartment): bool
    {
        $name = resolve_base_department($ticketDepartment);

        if ($name === '') {
            return true;
        }

        return self::rowAllowsCloud($this->findByName($name));
    }

    public static function rowAllowsCloud(?array $department): bool
    {
        return $department === null || (int) ($department['allow_cloud_ai'] ?? 1) === 1;
    }

    public function localOnlyNames(): array
    {
        return array_column(
            array_filter($this->allOrdered(), static fn ($d) => (int) ($d['allow_cloud_ai'] ?? 1) !== 1),
            'name'
        );
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

    public function staffCount(string $name): int
    {
        return (new UserModel())
            ->where('department', $name)
            ->whereIn('role', [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR])
            ->countAllResults();
    }
}
