<?php

namespace App\Libraries;

use App\Models\UserModel;

// tickets the assistant solved without a person, and how requesters rate its answers
class AiDeflection
{
    public const SELF_HELP_KINDS = ['suggestion', 'guide'];
    public const RATEABLE_KINDS  = ['suggestion', 'guide', 'message'];

    private const CHUNK = 500;

    public static function rateable(array $message): bool
    {
        return (string) ($message['author_role'] ?? '') === TicketAssistant::ROLE
            && in_array((string) ($message['kind'] ?? ''), self::RATEABLE_KINDS, true);
    }

    // resolved by the assistant or requester after a suggestion or guide, with no public staff reply
    public static function isDeflected(array $messages, ?array $resolveEvent, int $requesterId, string $requesterName): bool
    {
        if ($resolveEvent === null) {
            return false;
        }

        $actorId     = (int) ($resolveEvent['user_id'] ?? 0);
        $byAssistant = $actorId === 0 && (string) ($resolveEvent['actor_name'] ?? '') === TicketAssistant::AUTHOR;
        $byRequester = $requesterId > 0 && $actorId === $requesterId;

        if (! $byAssistant && ! $byRequester) {
            return false;
        }

        $helped = false;

        foreach ($messages as $m) {
            $role = (string) ($m['author_role'] ?? '');

            if ($role === TicketAssistant::ROLE) {
                $helped = $helped || in_array((string) ($m['kind'] ?? ''), self::SELF_HELP_KINDS, true);

                continue;
            }

            if ((int) ($m['is_internal'] ?? 0) === 1 || ! in_array($role, UserModel::STAFF_ROLES, true)) {
                continue;
            }

            $sameId   = $requesterId > 0 && (int) ($m['user_id'] ?? 0) === $requesterId;
            $sameName = $requesterName !== '' && strcasecmp(trim((string) ($m['author_name'] ?? '')), $requesterName) === 0;

            if (! $sameId && ! $sameName) {
                return false;
            }
        }

        return $helped;
    }

    public static function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    // ids of resolved tickets that count as deflected, tickets keyed by id with reqEmail and reqName
    public static function deflectedIds(array $tickets, array $userIdByEmail): array
    {
        if ($tickets === []) {
            return [];
        }

        $db     = \Config\Database::connect();
        $ids    = array_map('strval', array_keys($tickets));
        $events = [];

        try {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $rows = $db->table('ticket_events')->select('ticket_id, user_id, actor_name')
                    ->where('event', 'status')->where('to_value', 'Resolved')->whereIn('ticket_id', $chunk)
                    ->orderBy('id', 'ASC')->get()->getResultArray();

                foreach ($rows as $r) {
                    $events[(string) $r['ticket_id']] = $r;
                }
            }

            $messages = [];
            foreach (array_chunk(array_keys($events), self::CHUNK) as $chunk) {
                $rows = $db->table('ticket_messages')->select('ticket_id, user_id, author_name, author_role, kind, is_internal')
                    ->whereIn('ticket_id', array_map('strval', $chunk))->orderBy('id', 'ASC')->get()->getResultArray();

                foreach ($rows as $r) {
                    $messages[(string) $r['ticket_id']][] = $r;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'deflection count failed: {m}', ['m' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($events as $id => $event) {
            $t = $tickets[$id] ?? [];

            if (self::isDeflected($messages[$id] ?? [], $event, (int) ($userIdByEmail[$t['reqEmail'] ?? ''] ?? 0), (string) ($t['reqName'] ?? ''))) {
                $out[] = (string) $id;
            }
        }

        return $out;
    }

    // helpful and total votes on assistant answers in these tickets
    public static function votesFor(array $ticketIds): array
    {
        $helpful = $total = 0;

        if ($ticketIds === []) {
            return ['helpful' => 0, 'total' => 0];
        }

        $db = \Config\Database::connect();

        try {
            foreach (array_chunk(array_map('strval', $ticketIds), self::CHUNK) as $chunk) {
                $row = $db->table('ai_feedback f')
                    ->select('COUNT(*) AS total, COALESCE(SUM(f.helpful), 0) AS helpful', false)
                    ->join('ticket_messages m', 'm.id = f.message_id')
                    ->whereIn('m.ticket_id', $chunk)
                    ->get()->getRowArray();

                $helpful += (int) ($row['helpful'] ?? 0);
                $total   += (int) ($row['total'] ?? 0);
            }
        } catch (\Throwable $e) {
            return ['helpful' => 0, 'total' => 0];
        }

        return ['helpful' => $helpful, 'total' => $total];
    }
}
