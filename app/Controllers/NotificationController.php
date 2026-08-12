<?php

namespace App\Controllers;

use App\Models\NotificationModel;
use App\Models\UserModel;

class NotificationController extends BaseController
{
    public function index()
    {
        $user          = current_user();
        $notifications = new NotificationModel();
        $rows          = $notifications->forUser((int) $user['id']);

        $notifications->markAllRead((int) $user['id']);

        return view('notifications/index', ['notifications' => $rows]);
    }

    public function open(int $id)
    {
        $user  = current_user();
        $model = new NotificationModel();
        $row   = $model->where('id', $id)->where('user_id', (int) $user['id'])->first();

        if ($row === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $model->markRead($id, (int) $user['id']);

        if (! empty($row['ticket_id'])) {
            return redirect()->to('/tickets/' . $row['ticket_id'] . '#conversation');
        }

        return redirect()->to('/notifications');
    }

    public function mentionable()
    {
        $query = trim((string) $this->request->getGet('q'));
        $users = new UserModel();

        $builder = $users->where('is_active', 1);

        if ($query !== '') {
            $builder = $builder->groupStart()
                ->like('name', $query)
                ->orLike('email', $query)
                ->groupEnd();
        }

        $rows = $builder->orderBy('name', 'ASC')->limit(8)->findAll();

        return $this->response->setJSON(array_map(static fn ($u) => [
            'name'       => $u['name'],
            'role'       => $u['role'],
            'department' => $u['department'],
        ], $rows));
    }
}
