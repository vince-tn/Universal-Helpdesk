<?php

namespace App\Controllers;

use App\Libraries\Org;
use App\Models\NotificationModel;
use App\Models\TicketModel;

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
        $user   = current_user();
        $query  = mb_strtolower(trim((string) $this->request->getGet('q')));
        $ticket = (new TicketModel())->getTicket((string) (int) $this->request->getGet('ticket'));
        $fields = $ticket['fields'] ?? null;

        if ($fields === null || ! Org::canViewTicket($user, $fields)) {
            return $this->response->setJSON([]);
        }

        $rows = array_filter(
            Org::ticketAudience($fields),
            static fn ($u) => (int) $u['id'] !== (int) $user['id']
                && ($query === ''
                    || str_contains(mb_strtolower((string) $u['name']), $query)
                    || str_contains(mb_strtolower((string) $u['email']), $query))
        );

        return $this->response->setJSON(array_map(static fn ($u) => [
            'name'       => $u['name'],
            'role'       => role_label((string) $u['role']),
            'department' => $u['department'],
        ], array_slice(array_values($rows), 0, 8)));
    }
}
