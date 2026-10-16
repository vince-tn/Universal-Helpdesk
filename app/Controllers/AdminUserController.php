<?php

namespace App\Controllers;

use App\Models\UserModel;

class AdminUserController extends BaseController
{
    protected UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        $leads = db_connect()->table('departments')
            ->select('team_leader_id, name')
            ->where('team_leader_id IS NOT NULL')
            ->get()->getResultArray();

        $leadsOf = [];
        foreach ($leads as $l) {
            $leadsOf[(int) $l['team_leader_id']] = $l['name'];
        }

        return view('admin/users/index', [
            'users'     => $this->userModel->orderBy('role', 'ASC')->orderBy('name', 'ASC')->findAll(),
            'leadsOf'   => $leadsOf,
            'leaderIds' => array_keys($leadsOf),
        ]);
    }

    public function create()
    {
        return view('admin/users/create');
    }

    public function store()
    {
        $name       = trim((string) $this->request->getPost('name'));
        $email      = trim((string) $this->request->getPost('email'));
        $password   = (string) $this->request->getPost('password');
        $role       = (string) $this->request->getPost('role');
        $department = $this->request->getPost('department') ?: null;

        $allowedRoles = UserModel::ROLES;

        if ($name === '' || $email === '' || strlen($password) < 8 || ! in_array($role, $allowedRoles, true)) {
            return redirect()->back()->withInput()->with('error', 'Please fill in all fields correctly (password min. 8 characters).');
        }

        if (in_array($role, [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR], true) && ! in_array($department, departments(), true)) {
            return redirect()->back()->withInput()->with('error', 'Please choose a department for this role.');
        }

        if ($this->userModel->findByEmail($email) !== null) {
            return redirect()->back()->withInput()->with('error', 'An account with that email already exists.');
        }

        $scoped = in_array($role, [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR], true);

        $this->userModel->insert([
            'name'               => $name,
            'email'              => strtolower($email),
            'company'            => trim((string) $this->request->getPost('company')) ?: null,
            'password_hash'      => password_hash($password, PASSWORD_DEFAULT),
            'role'               => $role,
            'department'         => $scoped ? $department : null,
            'can_edit_knowledge' => $this->knowledgeFlag($role),
            'is_active'          => 1,
        ]);

        return redirect()->to('/admin/users')->with('success', "Account for {$name} created.");
    }

    public function edit(int $id)
    {
        $user = $this->userModel->find($id);

        if ($user === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $roles = db_connect()->table('department_roles r')
            ->select('r.id, r.name, d.name AS dept')
            ->join('departments d', 'd.id = r.department_id')
            ->orderBy('r.name', 'ASC')->get()->getResultArray();

        $byDept = [];
        foreach ($roles as $r) {
            $byDept[$r['dept']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }

        return view('admin/users/edit', ['editUser' => $user, 'deptRoles' => $byDept]);
    }

    public function update(int $id)
    {
        $user = $this->userModel->find($id);

        if ($user === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name       = trim((string) $this->request->getPost('name'));
        $role       = (string) $this->request->getPost('role');
        $department = $this->request->getPost('department') ?: null;
        $isActive   = $this->request->getPost('is_active') ? 1 : 0;
        $password   = (string) $this->request->getPost('password');

        $allowedRoles = UserModel::ROLES;

        if ($name === '' || ! in_array($role, $allowedRoles, true)) {
            return redirect()->back()->withInput()->with('error', 'Please fill in all fields correctly.');
        }

        if (in_array($role, [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR], true) && ! in_array($department, departments(), true)) {
            return redirect()->back()->withInput()->with('error', 'Please choose a department for this agent.');
        }

        $losesSuperadmin = $user['role'] === UserModel::ROLE_SUPERADMIN
            && (int) $user['is_active'] === 1
            && ($role !== UserModel::ROLE_SUPERADMIN || $isActive === 0);

        if ($losesSuperadmin && (int) current_user()['id'] === $id) {
            return redirect()->back()->withInput()->with('error', 'You cannot remove your own super admin access or deactivate yourself. Ask another super admin.');
        }

        if ($losesSuperadmin
            && $this->userModel->where('role', UserModel::ROLE_SUPERADMIN)->where('is_active', 1)->countAllResults() <= 1) {
            return redirect()->back()->withInput()->with('error', 'That is the last active super admin. Promote somebody else first.');
        }

        $data = [
            'name'               => $name,
            'role'               => $role,
            'department'         => in_array($role, [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR], true) ? $department : null,
            'company'            => trim((string) $this->request->getPost('company')) ?: null,
            'can_edit_knowledge' => $this->knowledgeFlag($role),
            'is_active'          => $isActive,
        ];

        if ($password !== '') {
            if (strlen($password) < 8) {
                return redirect()->back()->withInput()->with('error', 'Password must be at least 8 characters.');
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $data['department_role_id'] = in_array($role, [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER], true)
            ? $this->departmentRoleIn((int) $this->request->getPost('department_role_id'), (string) $department)
            : null;

        $this->userModel->update($id, $data);

        return redirect()->to('/admin/users')->with('success', "Account for {$name} updated.");
    }

    private function knowledgeFlag(string $role): int
    {
        return $role === UserModel::ROLE_AGENT && $this->request->getPost('can_edit_knowledge') !== null ? 1 : 0;
    }

    private function departmentRoleIn(int $roleId, string $department): ?int
    {
        if ($roleId <= 0 || $department === '') {
            return null;
        }

        $match = db_connect()->table('department_roles r')
            ->select('r.id')
            ->join('departments d', 'd.id = r.department_id')
            ->where('r.id', $roleId)
            ->where('d.name', $department)
            ->get()
            ->getRowArray();

        return $match === null ? null : $roleId;
    }

    public function delete(int $id)
    {
        $user = $this->userModel->find($id);

        if ($user === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $me = current_user();

        if ((int) $me['id'] === $id) {
            return redirect()->to('/admin/users')->with('error', 'You cannot delete your own account.');
        }

        if ($user['role'] === UserModel::ROLE_SUPERADMIN
            && $this->userModel->where('role', UserModel::ROLE_SUPERADMIN)->where('is_active', 1)->countAllResults() <= 1) {
            return redirect()->to('/admin/users')->with('error', 'That is the last active super admin. Promote somebody else first.');
        }

        $openTickets = db_connect()->table('ticket_meta')
            ->where('assigned_to', $id)
            ->countAllResults();

        if ($openTickets > 0) {
            db_connect()->table('ticket_meta')->where('assigned_to', $id)->update(['assigned_to' => null]);
        }

        $name = (string) $user['name'];
        $this->userModel->delete($id);

        $note = $openTickets > 0
            ? " {$openTickets} ticket(s) were unassigned."
            : '';

        return redirect()->to('/admin/users')->with('success', "Account for {$name} deleted." . $note);
    }

    public function deactivate(int $id)
    {
        $user = $this->userModel->find($id);

        if ($user === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $me = current_user();

        if ((int) $me['id'] === $id) {
            return redirect()->to('/admin/users')->with('error', 'You cannot deactivate your own account.');
        }

        $this->userModel->update($id, ['is_active' => (int) $user['is_active'] === 1 ? 0 : 1]);

        return redirect()->to('/admin/users')->with(
            'success',
            (int) $user['is_active'] === 1 ? "{$user['name']} deactivated." : "{$user['name']} reactivated."
        );
    }
}
