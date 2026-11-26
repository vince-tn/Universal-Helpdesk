<?php

namespace App\Libraries;

// writes the ticket activity log and the admin audit log
class Activity
{
    public static function ticket(string $ticketId, string $event, ?string $from = null, ?string $to = null, ?array $user = null): void
    {
        $user ??= current_user();

        try {
            \Config\Database::connect()->table('ticket_events')->insert([
                'ticket_id'  => $ticketId,
                'user_id'    => $user['id'] ?? null,
                'actor_name' => mb_substr((string) ($user['name'] ?? 'System'), 0, 150),
                'event'      => mb_substr($event, 0, 40),
                'from_value' => $from === null ? null : mb_substr($from, 0, 255),
                'to_value'   => $to === null ? null : mb_substr($to, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'activity log failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    public static function audit(string $action, string $detail = '', ?string $ticketId = null, ?array $actor = null): void
    {
        $user = $actor ?? current_user();

        try {
            \Config\Database::connect()->table('audit_log')->insert([
                'user_id'    => $user['id'] ?? null,
                'actor_name' => mb_substr((string) ($user['name'] ?? 'System'), 0, 150),
                'action'     => mb_substr($action, 0, 60),
                'ticket_id'  => $ticketId,
                'detail'     => mb_substr($detail, 0, 500),
                'ip'         => is_cli() ? null : service('request')->getIPAddress(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'audit log failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    public static function forTicket(string $ticketId): array
    {
        return \Config\Database::connect()->table('ticket_events')
            ->where('ticket_id', $ticketId)->orderBy('id', 'ASC')->get()->getResultArray();
    }

    public static function describe(array $e): string
    {
        $from = (string) ($e['from_value'] ?? '');
        $to   = (string) ($e['to_value'] ?? '');

        return match ($e['event']) {
            'created'   => 'opened the ticket',
            'status'    => 'changed status from ' . ($from ?: 'none') . ' to ' . $to,
            'priority'  => 'changed priority from ' . ($from ?: 'none') . ' to ' . $to,
            'assigned'  => $to === '' ? 'removed the assignee' : 'assigned it to ' . $to,
            'team'      => 'moved it from ' . ($from ?: 'none') . ' to ' . $to,
            'category'  => 'changed category to ' . $to,
            'due'       => 'set the due date to ' . $to,
            'approval'  => $to,
            'note'      => 'added an internal note',
            'routed'    => 'the assistant routed it to ' . $to . ($from !== '' ? ' (' . $from . ')' : ''),
            'reopened'  => 'reopened the ticket',
            'feedback'  => 'rated the help ' . $to . ' out of 5',
            default     => $e['event'] . ($to !== '' ? ': ' . $to : ''),
        };
    }
}
