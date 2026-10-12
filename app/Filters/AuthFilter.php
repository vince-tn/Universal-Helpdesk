<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('isLoggedIn')) {
            return redirect()->to('/login')->with('error', 'Please log in to continue.');
        }

        $id  = (int) ($session->get('user')['id'] ?? 0);
        $row = $id > 0 ? (new UserModel())->find($id) : null;

        if ($row === null || (int) $row['is_active'] !== 1) {
            $session->remove(['isLoggedIn', 'user']);

            return redirect()->to('/login')->with('error', 'Your account is no longer active. Contact your administrator.');
        }

        $fresh = [
            'id'         => $row['id'],
            'name'       => $row['name'],
            'email'      => $row['email'],
            'role'       => $row['role'],
            'department' => $row['department'],
        ];

        if ($fresh != $session->get('user')) {
            $session->set('user', $fresh);
        }

        $role = $fresh['role'];

        if ($arguments !== null && ! in_array($role, $arguments, true)) {
            return redirect()->to('/tickets')->with('error', "You don't have access to that page.");
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
