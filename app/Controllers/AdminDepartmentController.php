<?php

namespace App\Controllers;

use App\Models\DepartmentModel;
use App\Models\DepartmentRoleModel;
use App\Models\UserModel;

class AdminDepartmentController extends BaseController
{
    protected DepartmentModel $departments;
    protected DepartmentRoleModel $roles;

    public function __construct()
    {
        $this->departments = new DepartmentModel();
        $this->roles       = new DepartmentRoleModel();
    }

    public function index()
    {
        $departments = $this->departments->withRoles();

        foreach ($departments as &$department) {
            $department['agent_count'] = $this->departments->agentCount((string) $department['name']);
        }

        return view('admin/departments/index', ['departments' => $departments]);
    }

    public function store()
    {
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '' || mb_strlen($name) > 80) {
            return redirect()->back()->with('error', 'Enter a department name of 1 to 80 characters.');
        }

        if ($this->departments->findByName($name) !== null) {
            return redirect()->back()->with('error', 'A department called "' . $name . '" already exists.');
        }

        $this->departments->insert([
            'name'       => $name,
            'is_active'  => 1,
            'sort_order' => count($this->departments->allOrdered()),
        ]);

        return redirect()->to('/admin/departments')->with('success', 'Department "' . $name . '" added.');
    }

    public function update(int $id)
    {
        $department = $this->departments->find($id);

        if ($department === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '' || mb_strlen($name) > 80) {
            return redirect()->back()->with('error', 'Enter a department name of 1 to 80 characters.');
        }

        $clash = $this->departments->findByName($name);

        if ($clash !== null && (int) $clash['id'] !== $id) {
            return redirect()->back()->with('error', 'A department called "' . $name . '" already exists.');
        }

        $previous = (string) $department['name'];

        $this->departments->update($id, [
            'name'      => $name,
            'is_active' => $this->request->getPost('is_active') !== null ? 1 : 0,
        ]);

        if ($previous !== $name) {
            db_connect()->table('users')->where('department', $previous)->update(['department' => $name]);
            db_connect()->table('tickets')
                ->where('responsible_department', $previous)
                ->update(['responsible_department' => $name]);
        }

        return redirect()->to('/admin/departments')->with('success', 'Department updated.');
    }

    public function delete(int $id)
    {
        $department = $this->departments->find($id);

        if ($department === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name = (string) $department['name'];

        if ($this->departments->agentCount($name) > 0) {
            return redirect()->back()->with(
                'error',
                'Move the agents out of "' . $name . '" before deleting it, or deactivate it instead.'
            );
        }

        $roleIds = array_column($this->roles->forDepartment($id), 'id');

        if ($roleIds !== []) {
            db_connect()->table('users')->whereIn('department_role_id', $roleIds)->update(['department_role_id' => null]);
            $this->roles->whereIn('id', $roleIds)->delete();
        }

        $this->departments->delete($id);

        return redirect()->to('/admin/departments')->with('success', 'Department "' . $name . '" deleted.');
    }

    public function storeRole(int $departmentId)
    {
        if ($this->departments->find($departmentId) === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '' || mb_strlen($name) > 80) {
            return redirect()->back()->with('error', 'Enter a role name of 1 to 80 characters.');
        }

        if ($this->roles->exists($departmentId, $name)) {
            return redirect()->back()->with('error', 'That department already has a "' . $name . '" role.');
        }

        $this->roles->insert(['department_id' => $departmentId, 'name' => $name]);

        return redirect()->to('/admin/departments')->with('success', 'Role "' . $name . '" added.');
    }

    public function updateRole(int $id)
    {
        $role = $this->roles->find($id);

        if ($role === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '' || mb_strlen($name) > 80) {
            return redirect()->back()->with('error', 'Enter a role name of 1 to 80 characters.');
        }

        if ($this->roles->exists((int) $role['department_id'], $name, $id)) {
            return redirect()->back()->with('error', 'That department already has a "' . $name . '" role.');
        }

        $this->roles->update($id, ['name' => $name]);

        return redirect()->to('/admin/departments')->with('success', 'Role updated.');
    }

    public function deleteRole(int $id)
    {
        $role = $this->roles->find($id);

        if ($role === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        db_connect()->table('users')->where('department_role_id', $id)->update(['department_role_id' => null]);
        $this->roles->delete($id);

        return redirect()->to('/admin/departments')->with('success', 'Role "' . $role['name'] . '" deleted.');
    }
}
