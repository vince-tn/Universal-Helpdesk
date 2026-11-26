<?php

namespace App\Libraries;

use App\Models\TicketModel;
use App\Models\UserModel;

// rules and result text for dashboard bulk actions
class BulkTickets
{
    public const ACTIONS  = ['assign', 'status', 'priority', 'close'];
    public const MAX      = 200;
    public const NOTE_MIN = 10;

    private const REASONS = [
        'access'         => 'not yours to manage',
        'unchanged'      => 'already set',
        'approval'       => 'waiting for approval',
        'unassigned'     => 'with no assignee',
        'not_assignable' => 'not on that person\'s team',
        'missing'        => 'not found',
    ];

    public static function canUse(?array $user): bool
    {
        return in_array($user['role'] ?? '', [UserModel::ROLE_SUPERADMIN, UserModel::ROLE_SUPERVISOR, UserModel::ROLE_TEAM_LEADER], true);
    }

    // close is a status change to closed
    public static function normalise(string $action, string $value): array
    {
        return $action === 'close' ? ['status', 'Closed'] : [$action, $value];
    }

    // checks the request as a whole, returns an error or null
    public static function requestError(string $action, string $value, string $note): ?string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return 'Pick an action.';
        }

        [$action, $value] = self::normalise($action, $value);

        return match ($action) {
            'status' => match (true) {
                ! in_array($value, TicketModel::STATUSES, true) => 'Pick a status.',
                $value === 'Resolved' && mb_strlen(trim($note)) < self::NOTE_MIN => 'Add a short resolution note (what fixed it) before resolving.',
                default => null,
            },
            'priority' => in_array($value, priorities(), true) ? null : 'Pick a priority.',
            'assign'   => (int) $value > 0 ? null : 'Pick someone to assign.',
            default    => 'Pick an action.',
        };
    }

    // why one ticket is skipped, or null when the change applies
    public static function skipReason(string $action, string $value, array $ticket, bool $superadmin): ?string
    {
        if (empty($ticket['can_manage'])) {
            return 'access';
        }

        [$action, $value] = self::normalise($action, $value);

        $status   = (string) ($ticket['status'] ?? 'New');
        $assignee = (int) ($ticket['assigned_to'] ?? 0);

        return match ($action) {
            'status' => match (true) {
                $status === $value => 'unchanged',
                $status === 'Pending Approval' && ! $superadmin => 'approval',
                $value === 'Resolved' && $assignee <= 0 => 'unassigned',
                default => null,
            },
            'priority' => (string) ($ticket['priority'] ?? 'Medium') === $value ? 'unchanged' : null,
            'assign'   => match (true) {
                $assignee === (int) $value => 'unchanged',
                ! in_array((int) $value, array_map('intval', $ticket['assignable'] ?? []), true) => 'not_assignable',
                default => null,
            },
            default => 'access',
        };
    }

    // updated 7 tickets, skipped 2 with the reasons
    public static function summary(int $updated, array $skipped): string
    {
        $skippedTotal = array_sum($skipped);
        $text = 'Updated ' . $updated . ' ticket' . ($updated === 1 ? '' : 's');

        if ($skippedTotal === 0) {
            return $text . '.';
        }

        $parts = [];
        foreach ($skipped as $reason => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . (self::REASONS[$reason] ?? $reason);
            }
        }

        return $text . ', skipped ' . $skippedTotal . ' (' . implode(', ', $parts) . ').';
    }
}
