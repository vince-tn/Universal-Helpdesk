<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketMessageModel extends Model
{
    protected $table         = 'ticket_messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id', 'user_id', 'author_name', 'author_role',
        'kind', 'ai_confidence', 'is_solution', 'body', 'created_at', 'is_internal',
        'solution_state', 'solution_reviewed_by', 'solution_reviewed_at',
    ];

    public const SOLUTION_PENDING  = 'pending';
    public const SOLUTION_APPROVED = 'approved';
    public const SOLUTION_REJECTED = 'rejected';

    public const KIND_MESSAGE     = 'message';
    public const KIND_SUGGESTION  = 'suggestion';
    public const KIND_ACKNOWLEDGE = 'acknowledge';
    public const KIND_HANDOFF     = 'handoff';
    public const KIND_GUIDE       = 'guide';

    public function forTicket(string $ticketId, bool $withInternal = true): array
    {
        if (! $withInternal) {
            $this->where('is_internal', 0);
        }

        return $this->where('ticket_id', $ticketId)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    public function latestForTicket(string $ticketId): ?array
    {
        return $this->where('ticket_id', $ticketId)->orderBy('id', 'DESC')->first();
    }

    public function hasAssistantMessage(string $ticketId): bool
    {
        return $this->where('ticket_id', $ticketId)
            ->where('author_role', 'ai')
            ->countAllResults() > 0;
    }

    public function post(
        string $ticketId,
        ?int $userId,
        string $authorName,
        string $authorRole,
        string $body,
        string $kind = self::KIND_MESSAGE,
        ?string $confidence = null,
        bool $isSolution = false,
        bool $internal = false
    ): int|string|false {
        return $this->insert([
            'ticket_id'     => $ticketId,
            'user_id'       => $userId,
            'author_name'   => $authorName,
            'author_role'   => $authorRole,
            'kind'          => $kind,
            'ai_confidence' => $confidence,
            'is_solution'    => $isSolution ? 1 : 0,
            'solution_state' => $isSolution ? self::SOLUTION_PENDING : null,
            'is_internal'    => $internal ? 1 : 0,
            'body'           => $body,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    public function markSolution(int $id, bool $on = true): bool
    {
        return $this->update($id, [
            'is_solution'    => $on ? 1 : 0,
            'solution_state' => $on ? self::SOLUTION_PENDING : null,
        ]);
    }

    public function reviewSolution(int $id, string $state, int $reviewerId): bool
    {
        return $this->update($id, [
            'solution_state'       => $state,
            'solution_reviewed_by' => $reviewerId,
            'solution_reviewed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
