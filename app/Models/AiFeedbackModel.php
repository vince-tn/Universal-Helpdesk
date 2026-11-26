<?php

namespace App\Models;

use CodeIgniter\Model;

// this helped or not helpful votes on assistant answers
class AiFeedbackModel extends Model
{
    protected $table         = 'ai_feedback';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['message_id', 'user_id', 'helpful', 'created_at'];

    // one vote per user per message, a new click replaces the old one
    public function vote(int $messageId, int $userId, bool $helpful): bool
    {
        $row = ['helpful' => $helpful ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')];

        try {
            $existing = $this->where(['message_id' => $messageId, 'user_id' => $userId])->first();

            if ($existing !== null) {
                return $this->update((int) $existing['id'], $row);
            }

            return $this->insert($row + ['message_id' => $messageId, 'user_id' => $userId]) !== false;
        } catch (\Throwable $e) {
            log_message('error', 'ai feedback save failed: {m}', ['m' => $e->getMessage()]);

            return false;
        }
    }

    // message id to 1 or 0 for this user
    public function votesBy(int $userId, array $messageIds): array
    {
        if ($userId <= 0 || $messageIds === []) {
            return [];
        }

        try {
            $rows = $this->select('message_id, helpful')->where('user_id', $userId)->whereIn('message_id', $messageIds)->findAll();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['message_id']] = (int) $r['helpful'];
        }

        return $out;
    }
}
