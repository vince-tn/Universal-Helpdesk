<?php

namespace App\Models;

use CodeIgniter\Model;

class DepartmentRoleModel extends Model
{
    protected $table         = 'department_roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['department_id', 'name'];

    public function forDepartment(int $departmentId): array
    {
        return $this->where('department_id', $departmentId)->orderBy('name', 'ASC')->findAll();
    }

    public function exists(int $departmentId, string $name, ?int $ignoreId = null): bool
    {
        $query = $this->where('department_id', $departmentId)->where('name', trim($name));

        if ($ignoreId !== null) {
            $query = $query->where('id !=', $ignoreId);
        }

        return $query->countAllResults() > 0;
    }

    public function holderCount(int $roleId): int
    {
        return (new UserModel())->where('department_role_id', $roleId)->countAllResults();
    }
}
