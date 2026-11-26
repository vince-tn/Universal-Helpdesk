<?php

namespace App\Libraries;

use App\Models\TicketModel;

// finds likely repeats of a request and handles merge and link
class Duplicates
{
    public const WINDOW_DAYS = 14;
    public const THRESHOLD   = 0.35;

    private const STOP = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'has', 'was', 'are', 'not', 'but', 'you', 'your',
        'our', 'can', 'please', 'need', 'help', 'thanks', 'thank', 'hi', 'hello', 'there', 'when', 'what', 'into',
        'just', 'its', 'it', 'will', 'would', 'could', 'should', 'been', 'also', 'any', 'all', 'some', 'get', 'got',
        'request', 'type', 'unclassified',
    ];

    // word overlap score between two requests, 0 to 1
    public static function similarity(string $a, string $b): float
    {
        $wa = self::words($a);
        $wb = self::words($b);

        if ($wa === [] || $wb === []) {
            return 0.0;
        }

        $shared = count(array_intersect_key($wa, $wb));
        $union  = count($wa + $wb);
        $small  = min(count($wa), count($wb));

        // blend of jaccard and containment so a short repeat of a long ticket still scores
        return round((($shared / $union) + ($shared / $small)) / 2, 4);
    }

    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);

        $out = [];
        foreach ($m[0] ?? [] as $word) {
            if (! in_array($word, self::STOP, true)) {
                $out[$word] = true;
            }
        }

        return $out;
    }

    public static function textOf(array $fields): string
    {
        $title = trim((string) ($fields['Request Title'] ?? ''));

        if (strcasecmp($title, 'Unclassified Request') === 0) {
            $title = '';
        }

        return $title . ' ' . (string) ($fields['Request'] ?? '');
    }

    // best open match from the same requester in the window, saved on the ticket
    public function detect(string $ticketId, array $fields): ?int
    {
        $email = trim((string) ($fields['Requester Email'] ?? ''));

        if ($email === '') {
            return null;
        }

        try {
            $rows = (new TicketModel())
                ->where('requester_email', $email)
                ->where('id !=', (int) $ticketId)
                ->whereIn('status', TicketModel::OPEN_STATUSES)
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_DAYS . ' days')))
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->findAll();

            $text = self::textOf($fields);
            $best = null;
            $top  = 0.0;

            foreach ($rows as $row) {
                $score = self::similarity($text, ((string) ($row['request_title'] ?? '')) . ' ' . ((string) ($row['request_body'] ?? '')));

                if ($score >= self::THRESHOLD && $score > $top) {
                    $top  = $score;
                    $best = (int) $row['id'];
                }
            }

            if ($best !== null) {
                (new TicketModel())->update((int) $ticketId, ['duplicate_of' => $best]);
            }

            return $best;
        } catch (\Throwable $e) {
            log_message('error', 'duplicate check failed on #{id}: {m}', ['id' => $ticketId, 'm' => $e->getMessage()]);

            return null;
        }
    }

    // copies the source into the target as an internal note, closes the source and tells the requester
    public function merge(string $sourceId, array $source, string $targetId, array $target, array $actor): void
    {
        $messages = new \App\Models\TicketMessageModel();
        $tickets  = new TicketModel();

        $lines = [
            'Merged from #' . $sourceId . ': ' . trim((string) ($source['Request Title'] ?? $source['Title'] ?? '')),
            'Requester: ' . trim((string) ($source['Requester'] ?? '')) . ' (' . trim((string) ($source['Requester Email'] ?? '')) . ')',
            '',
            'Original request:',
            trim((string) ($source['Request'] ?? '')),
        ];

        $thread = $messages->forTicket($sourceId, true);

        if ($thread !== []) {
            $lines[] = '';
            $lines[] = 'Conversation on #' . $sourceId . ':';
            foreach ($thread as $m) {
                $lines[] = '- ' . trim((string) ($m['author_name'] ?? 'Someone')) . ', ' . format_when($m['created_at'] ?? null)
                    . ((int) ($m['is_internal'] ?? 0) === 1 ? ' (internal)' : '') . ': ' . Notifier::excerpt((string) ($m['body'] ?? ''), 600);
            }
        }

        $note = mb_substr(implode("\n", $lines), 0, 9000);

        $messages->post($targetId, $actor['id'] ?? null, (string) $actor['name'], (string) $actor['role'], $note, \App\Models\TicketMessageModel::KIND_MESSAGE, null, false, true);
        $messages->post($sourceId, $actor['id'] ?? null, (string) $actor['name'], (string) $actor['role'], 'Merged into #' . $targetId . '. Follow it there.', \App\Models\TicketMessageModel::KIND_MESSAGE);

        $from = (string) ($source['Status'] ?? 'New');
        $tickets->update((int) $sourceId, ['status' => 'Closed', 'resolution_note' => 'Merged into #' . $targetId, 'duplicate_of' => null]);
        (new Sla())->onStatusChange($sourceId, $from, 'Closed');

        if ((int) ($target['Duplicate Of'] ?? 0) === (int) $sourceId) {
            $tickets->update((int) $targetId, ['duplicate_of' => null]);
        }

        Activity::ticket($sourceId, 'status', $from, 'Closed', $actor);
        Activity::ticket($sourceId, 'merged', null, '#' . $targetId, $actor);
        Activity::ticket($targetId, 'merged_from', null, '#' . $sourceId, $actor);

        $people = array_filter([(string) ($source['Requester Email'] ?? ''), (string) ($source['Requested For'] ?? '')]);
        [$users] = Watchers::resolve(array_map('strtolower', $people));
        Watchers::add($targetId, $target, $users, $actor);

        (new Notifier())->merged($sourceId, $source, $targetId);
    }

    // ids linked to the ticket either way round
    public static function links(string $ticketId): array
    {
        try {
            $db   = \Config\Database::connect();
            $rows = $db->table('ticket_links')->groupStart()->where('ticket_id', (int) $ticketId)->orWhere('linked_id', (int) $ticketId)->groupEnd()->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['ticket_id'] === (int) $ticketId ? (int) $row['linked_id'] : (int) $row['ticket_id'];
        }

        return array_values(array_unique($ids));
    }

    public static function link(string $a, string $b, array $actor): bool
    {
        [$low, $high] = (int) $a < (int) $b ? [(int) $a, (int) $b] : [(int) $b, (int) $a];

        $db = \Config\Database::connect();

        if ($low === $high || $db->table('ticket_links')->where(['ticket_id' => $low, 'linked_id' => $high])->countAllResults() > 0) {
            return false;
        }

        $db->table('ticket_links')->insert([
            'ticket_id'  => $low,
            'linked_id'  => $high,
            'created_by' => $actor['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Activity::ticket($a, 'linked', null, '#' . $b, $actor);
        Activity::ticket($b, 'linked', null, '#' . $a, $actor);

        return true;
    }

    public static function unlink(string $a, string $b, array $actor): bool
    {
        [$low, $high] = (int) $a < (int) $b ? [(int) $a, (int) $b] : [(int) $b, (int) $a];

        $db = \Config\Database::connect();
        $db->table('ticket_links')->where(['ticket_id' => $low, 'linked_id' => $high])->delete();

        if ($db->affectedRows() < 1) {
            return false;
        }

        Activity::ticket($a, 'unlinked', null, '#' . $b, $actor);
        Activity::ticket($b, 'unlinked', null, '#' . $a, $actor);

        return true;
    }
}
