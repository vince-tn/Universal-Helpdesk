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
        return view('admin/users/index', [
            'users' => $this->userModel->orderBy('role', 'ASC')->orderBy('name', 'ASC')->findAll(),
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

        $allowedRoles = [UserModel::ROLE_SUPERADMIN, UserModel::ROLE_AGENT, UserModel::ROLE_REQUESTER];

        if ($name === '' || $email === '' || strlen($password) < 8 || ! in_array($role, $allowedRoles, true)) {
            return redirect()->back()->withInput()->with('error', 'Please fill in all fields correctly (password min. 8 characters).');
        }

        if ($role === UserModel::ROLE_AGENT && ! in_array($department, departments(), true)) {
            return redirect()->back()->withInput()->with('error', 'Please choose a department for this agent.');
        }

        if ($this->userModel->findByEmail($email) !== null) {
            return redirect()->back()->withInput()->with('error', 'An account with that email already exists.');
        }

        $this->userModel->createAccount($name, $email, $password, $role, $role === UserModel::ROLE_AGENT ? $department : null);

        return redirect()->to('/admin/users')->with('success', "Account for {$name} created.");
    }

    public function edit(int $id)
    {
        $user = $this->userModel->find($id);

        if ($user === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('admin/users/edit', ['editUser' => $user]);
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

        $allowedRoles = [UserModel::ROLE_SUPERADMIN, UserModel::ROLE_AGENT, UserModel::ROLE_REQUESTER];

        if ($name === '' || ! in_array($role, $allowedRoles, true)) {
            return redirect()->back()->withInput()->with('error', 'Please fill in all fields correctly.');
        }

        if ($role === UserModel::ROLE_AGENT && ! in_array($department, departments(), true)) {
            return redirect()->back()->withInput()->with('error', 'Please choose a department for this agent.');
        }

        $data = [
            'name'       => $name,
            'role'       => $role,
            'department' => $role === UserModel::ROLE_AGENT ? $department : null,
            'is_active'  => $isActive,
        ];

        if ($password !== '') {
            if (strlen($password) < 8) {
                return redirect()->back()->withInput()->with('error', 'Password must be at least 8 characters.');
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $roleId = $this->request->getPost('department_role_id');
        $data['department_role_id'] = ($role === UserModel::ROLE_AGENT && $roleId) ? (int) $roleId : null;

        $this->userModel->update($id, $data);

        return redirect()->to('/admin/users')->with('success', "Account for {$name} updated.");
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
