<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table         = 'notifications';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['user_id', 'ticket_id', 'actor_name', 'type', 'body', 'is_read', 'created_at'];

    public function push(int $userId, ?string $ticketId, string $actorName, string $body, string $type = 'mention'): void
    {
        $this->insert([
            'user_id'    => $userId,
            'ticket_id'  => $ticketId,
            'actor_name' => $actorName,
            'type'       => $type,
            'body'       => mb_substr($body, 0, 500),
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function forUser(int $userId, int $limit = 30): array
    {
        return $this->where('user_id', $userId)->orderBy('id', 'DESC')->limit($limit)->findAll();
    }

    public function unreadCount(int $userId): int
    {
        return $this->where('user_id', $userId)->where('is_read', 0)->countAllResults();
    }

    public function markAllRead(int $userId): void
    {
        $this->db->table($this->table)->where('user_id', $userId)->where('is_read', 0)->update(['is_read' => 1]);
    }

    public function markRead(int $id, int $userId): void
    {
        $this->db->table($this->table)->where('id', $id)->where('user_id', $userId)->update(['is_read' => 1]);
    }
}
