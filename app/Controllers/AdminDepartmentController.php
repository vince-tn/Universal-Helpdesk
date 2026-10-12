<?php

namespace App\Controllers;

use App\Libraries\Org;
use App\Models\DepartmentModel;
use App\Models\DepartmentRoleModel;
use App\Models\GuardrailModel;
use App\Models\KnowledgeArticleModel;
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
        $me          = current_user();
        $departments = Org::manageableDepartments($me);
        $users       = new UserModel();
        $articles    = (new KnowledgeArticleModel())->countsByDepartment();
        $rules       = (new GuardrailModel())->countsByDepartment();

        foreach ($departments as &$department) {
            $department['staff_count']     = $this->departments->staffCount((string) $department['name']);
            $department['leads']           = $users->leadCandidates((string) $department['name']);
            $department['article_count']   = $articles[(int) $department['id']] ?? 0;
            $department['guardrail_count'] = $rules[(int) $department['id']] ?? 0;
        }
        unset($department);

        return view('admin/departments/index', [
            'departments'      => $departments,
            'supervisors'      => $users->supervisors(),
            'canCreate'        => Org::isSuperadmin($me),
            'isSuper'          => Org::isSuperadmin($me),
            'globalArticles'   => $articles['all'] ?? 0,
            'globalGuardrails' => $rules['all'] ?? 0,
        ]);
    }

    public function bulk()
    {
        $me      = current_user();
        $isSuper = Org::isSuperadmin($me);
        $rows    = (array) $this->request->getPost('dept');
        $saved   = 0;

        foreach ($rows as $id => $fields) {
            $id = (int) $id;

            if (! Org::canManageDepartment($me, $id)) {
                continue;
            }

            $name = trim((string) ($fields['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 80) {
                continue;
            }

            $current = $this->departments->find($id);

            if ($current === null) {
                continue;
            }

            $clash = $this->departments->findByName($name);

            if ($clash !== null && (int) $clash['id'] !== $id) {
                return redirect()->back()->with('error', 'A department called "' . $name . '" already exists.');
            }

            $data = [
                'name'           => $name,
                'is_active'      => (int) ($fields['is_active'] ?? 1) === 1 ? 1 : 0,
                'team_leader_id' => $this->leaderFor((int) ($fields['team_leader_id'] ?? 0), $current),
            ];

            if ($isSuper) {
                $data['supervisor_id'] = (int) ($fields['supervisor_id'] ?? 0) ?: null;
            }

            if (array_key_exists('handles', $fields)) {
                $data['handles'] = $this->handles($fields['handles']);
            }

            $this->departments->update($id, $data);

            if ($data['team_leader_id'] !== null) {
                db_connect()->table('users')
                    ->where('id', $data['team_leader_id'])
                    ->where('role', UserModel::ROLE_AGENT)
                    ->update(['role' => UserModel::ROLE_TEAM_LEADER]);
            }

            $previous = (string) $current['name'];

            if ($previous !== $name) {
                $this->renameEverywhere($previous, $name);
            }

            $saved++;
        }

        foreach ((array) $this->request->getPost('role') as $roleId => $input) {
            $input    = is_array($input) ? $input : ['name' => $input];
            $role     = $this->roles->find((int) $roleId);
            $roleName = trim((string) ($input['name'] ?? ''));

            if ($role === null || $roleName === '' || mb_strlen($roleName) > 80) {
                continue;
            }

            if (! Org::canManageDepartment($me, (int) $role['department_id'])) {
                continue;
            }

            $update = [];

            if (! $this->roles->exists((int) $role['department_id'], $roleName, (int) $roleId)) {
                $update['name'] = $roleName;
            }

            if (array_key_exists('handles', $input)) {
                $update['handles'] = $this->handles($input['handles']);
            }

            if ($update !== []) {
                $this->roles->update((int) $roleId, $update);
            }
        }

        foreach ((array) $this->request->getPost('newrole') as $deptId => $input) {
            $input    = is_array($input) ? $input : ['name' => $input];
            $deptId   = (int) $deptId;
            $roleName = trim((string) ($input['name'] ?? ''));

            if ($roleName === '' || mb_strlen($roleName) > 80) {
                continue;
            }

            if (! Org::canManageDepartment($me, $deptId) || $this->roles->exists($deptId, $roleName)) {
                continue;
            }

            $this->roles->insert([
                'department_id' => $deptId,
                'name'          => $roleName,
                'handles'       => $this->handles($input['handles'] ?? ''),
            ]);
        }

        return redirect()->to('/admin/departments')
            ->with('success', $saved === 0 ? 'Nothing to save.' : $saved . ' department(s) saved.');
    }

    private function handles($raw): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');

        return $value === '' ? null : mb_substr($value, 0, DepartmentModel::HANDLES_MAX);
    }

    private function leaderFor(int $leaderId, array $department): ?int
    {
        if ($leaderId <= 0) {
            return null;
        }

        foreach ((new UserModel())->leadCandidates((string) $department['name']) as $candidate) {
            if ((int) $candidate['id'] === $leaderId) {
                return $leaderId;
            }
        }

        $current = (int) ($department['team_leader_id'] ?? 0);

        return $current > 0 ? $current : null;
    }

    private function guard(int $id): void
    {
        if (! Org::canManageDepartment(current_user(), $id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
    }

    private function superOnly(): void
    {
        if (! Org::isSuperadmin(current_user())) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
    }

    public function store()
    {
        $this->superOnly();

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
        $this->guard($id);

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

        if (Org::isSuperadmin(current_user())) {
            $supervisorId = (int) $this->request->getPost('supervisor_id');
            $this->departments->update($id, ['supervisor_id' => $supervisorId > 0 ? $supervisorId : null]);
        }

        $leaderId = $this->leaderFor((int) $this->request->getPost('team_leader_id'), $department);
        $this->departments->update($id, ['team_leader_id' => $leaderId]);

        if ($leaderId !== null) {
            db_connect()->table('users')
                ->where('id', $leaderId)
                ->where('role', UserModel::ROLE_AGENT)
                ->update(['role' => UserModel::ROLE_TEAM_LEADER]);
        }

        if ($previous !== $name) {
            $this->renameEverywhere($previous, $name);
        }

        return redirect()->to('/admin/departments')->with('success', 'Department updated.');
    }

    private function renameEverywhere(string $previous, string $name): void
    {
        $db = db_connect();

        $db->table('users')->where('department', $previous)->update(['department' => $name]);

        foreach (['responsible_department', 'submitting_department'] as $column) {
            $db->table('tickets')
                ->groupStart()
                    ->where($column, $previous)
                    ->orLike($column, $previous . ' ', 'after')
                    ->orLike($column, $previous . '-', 'after')
                    ->orLike($column, $previous . '/', 'after')
                ->groupEnd()
                ->update([$column => $name]);
        }
    }

    /**
     * Tickets the department owns - the same rule as ticket_department(): the
     * team it was routed to, or the team it was raised with until it is routed.
     * Old names like "Compliance HQ" count for "Compliance", as in
     * renameEverywhere().
     */
    private function ticketsOwnedBy(string $name): int
    {
        $named = static fn ($builder, string $column) => $builder
            ->groupStart()
                ->where($column, $name)
                ->orLike($column, $name . ' ', 'after')
                ->orLike($column, $name . '-', 'after')
                ->orLike($column, $name . '/', 'after')
            ->groupEnd();

        $builder = db_connect()->table('tickets')->groupStart();
        $named($builder, 'responsible_department');
        $builder->orGroupStart()->where('responsible_department IS NULL', null, false);
        $named($builder, 'submitting_department');

        return $builder->groupEnd()->groupEnd()->countAllResults();
    }

    public function delete(int $id)
    {
        $this->superOnly();

        $department = $this->departments->find($id);

        if ($department === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name = (string) $department['name'];

        if ($this->departments->staffCount($name) > 0) {
            return redirect()->back()->with(
                'error',
                'Move the staff out of "' . $name . '" before deleting it, or deactivate it instead.'
            );
        }

        // Deleting it would leave these tickets owned by a team that no longer
        // exists, which only super admins (and each requester) can still see.
        $tickets = $this->ticketsOwnedBy($name);

        if ($tickets > 0) {
            return redirect()->back()->with(
                'error',
                $tickets . ' ticket' . ($tickets === 1 ? '' : 's') . ' still belong to "' . $name
                    . '". Deactivate the department instead to keep them, or move them to another team first.'
            );
        }

        (new KnowledgeArticleModel())->where('department_id', $id)->delete();
        (new GuardrailModel())->where('department_id', $id)->delete();

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
        $this->guard($departmentId);

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

        if ($role !== null) {
            $this->guard((int) $role['department_id']);
        }

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

        if ($role !== null) {
            $this->guard((int) $role['department_id']);
        }

        if ($role === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        db_connect()->table('users')->where('department_role_id', $id)->update(['department_role_id' => null]);
        $this->roles->delete($id);

        return redirect()->to('/admin/departments')->with('success', 'Role "' . $role['name'] . '" deleted.');
    }
}
