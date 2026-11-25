<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\KnowledgeArticleModel;
use App\Models\TicketMessageModel;
use App\Models\TicketModel;

// leads approve ticked answers before the assistant may reuse them
class KnowledgeReview
{
    public static function canReview(?array $user, string $ticketDepartment): bool
    {
        if (Org::isSuperadmin($user)) {
            return true;
        }

        if (! Org::isSupervisor($user) && ! Org::isTeamLeader($user)) {
            return false;
        }

        $department = strtolower(resolve_base_department($ticketDepartment));

        return $department !== '' && in_array($department, array_map('strtolower', Org::departmentNames($user)), true);
    }

    // pending answers this user may review, oldest first
    public function pending(array $user): array
    {
        $rows = db_connect()->table('ticket_messages m')
            ->select('m.id, m.ticket_id, m.author_name, m.body, m.created_at, t.request_title, t.responsible_department, t.submitting_department, t.status')
            ->join('tickets t', 't.id = m.ticket_id')
            ->where('m.is_solution', 1)
            ->where('m.solution_state', TicketMessageModel::SOLUTION_PENDING)
            ->where('m.is_internal', 0)
            ->orderBy('m.id', 'ASC')
            ->limit(300)
            ->get()
            ->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $department = ticket_department([
                'Responsible Department' => $row['responsible_department'],
                'Submitting Department'  => $row['submitting_department'],
            ]);

            if (self::canReview($user, (string) $department)) {
                $out[] = $row + ['department' => resolve_base_department((string) $department)];
            }
        }

        return $out;
    }

    // approve, reject or approve and save as an article, returns [ok, message]
    public function decide(int $messageId, array $user, string $action): array
    {
        if (! in_array($action, ['approve', 'reject', 'article'], true)) {
            return [false, 'Choose approve or reject.'];
        }

        $messages = new TicketMessageModel();
        $message  = $messages->find($messageId);

        if ($message === null || ($message['solution_state'] ?? '') !== TicketMessageModel::SOLUTION_PENDING) {
            return [false, 'That answer is no longer waiting for review.'];
        }

        $ticketId = (string) $message['ticket_id'];
        $fields   = (new TicketModel())->getTicket($ticketId)['fields'] ?? null;

        if ($fields === null || ! self::canReview($user, ticket_department($fields))) {
            return [false, 'You cannot review answers for that team.'];
        }

        if ($action === 'reject') {
            $messages->reviewSolution($messageId, TicketMessageModel::SOLUTION_REJECTED, (int) $user['id']);
            Activity::audit('knowledge_rejected', 'answer ' . $messageId, $ticketId);

            return [true, "Answer on #{$ticketId} rejected. The assistant will not reuse it."];
        }

        if ($action === 'article') {
            $departmentId = (new DepartmentModel())->idFor(ticket_department($fields));

            if ($departmentId === null) {
                return [false, 'This ticket is not with a known department, so it cannot become an article.'];
            }

            $title = trim((string) ($fields['Request Title'] ?? '')) ?: 'Answer from ticket #' . $ticketId;

            (new KnowledgeArticleModel())->insert([
                'department_id' => $departmentId,
                'title'         => mb_substr($title, 0, KnowledgeArticleModel::TITLE_MAX),
                'keywords'      => null,
                'body'          => mb_substr(trim((string) $message['body']), 0, KnowledgeArticleModel::BODY_MAX),
                'is_active'     => 1,
            ]);
        }

        $messages->reviewSolution($messageId, TicketMessageModel::SOLUTION_APPROVED, (int) $user['id']);
        Activity::audit($action === 'article' ? 'knowledge_article' : 'knowledge_approved', 'answer ' . $messageId, $ticketId);

        return [true, $action === 'article'
            ? "Answer on #{$ticketId} approved and saved as an article."
            : "Answer on #{$ticketId} approved. The assistant can reuse it."];
    }
}
