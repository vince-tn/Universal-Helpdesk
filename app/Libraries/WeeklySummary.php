<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\TicketMetaModel;
use App\Models\TicketModel;
use App\Models\UserModel;

// monday morning summary of the last 7 days for team leads and super admins
class WeeklySummary
{
    public const TZ      = 'Asia/Manila';
    public const SETTING = 'weekly_summary_last_run';
    public const BUCKETS = ['0 to 2 days', '3 to 5 days', '6+ days'];

    private const DONE  = ['Resolved', 'Closed'];
    private const CHUNK = 500;

    // monday 08:00 to 08:10 manila time and not yet sent this iso week
    public static function isDue(\DateTimeImmutable $now, string $lastRun): bool
    {
        $tz  = new \DateTimeZone(self::TZ);
        $now = $now->setTimezone($tz);

        if ($now->format('N') !== '1' || $now->format('H') !== '08' || (int) $now->format('i') > 10) {
            return false;
        }

        if (trim($lastRun) === '') {
            return true;
        }

        try {
            $last = new \DateTimeImmutable($lastRun, $tz);
        } catch (\Throwable $e) {
            return true;
        }

        return $last->format('o-W') !== $now->format('o-W');
    }

    public static function emptyStats(): array
    {
        return [
            'new' => 0, 'resolved' => 0, 'open' => 0,
            'ageing' => array_fill_keys(self::BUCKETS, 0),
            'slaMet' => 0, 'slaBreached' => 0,
            'categories' => [], 'csat' => [], 'deflected' => 0,
        ];
    }

    public static function bucket(int $ageDays): string
    {
        return $ageDays <= 2 ? self::BUCKETS[0] : ($ageDays <= 5 ? self::BUCKETS[1] : self::BUCKETS[2]);
    }

    // plain text body for one scope
    public static function text(array $s, string $scope, string $from, string $to, string $link): string
    {
        $s += self::emptyStats();

        $categories = $s['categories'];
        arsort($categories);
        $top = array_slice($categories, 0, 3, true);

        $lines = [
            'Weekly helpdesk summary for ' . $scope,
            $from . ' to ' . $to,
            '',
            'New tickets: ' . $s['new'],
            'Resolved: ' . $s['resolved'],
            'Open backlog: ' . $s['open'],
        ];
        foreach (self::BUCKETS as $b) {
            $lines[] = '  ' . $b . ': ' . (int) ($s['ageing'][$b] ?? 0);
        }

        $lines[] = 'SLA met: ' . $s['slaMet'];
        $lines[] = 'SLA breached: ' . $s['slaBreached'];
        $lines[] = 'Top categories:';

        if ($top === []) {
            $lines[] = '  None this week';
        } else {
            $n = 1;
            foreach ($top as $name => $count) {
                $lines[] = '  ' . $n++ . '. ' . $name . ' (' . $count . ')';
            }
        }

        $csat    = $s['csat'];
        $lines[] = $csat === []
            ? 'CSAT average: no ratings this week'
            : 'CSAT average: ' . number_format(array_sum($csat) / count($csat), 1) . ' / 5 from ' . count($csat) . ' rating' . (count($csat) === 1 ? '' : 's');

        $pct     = AiDeflection::percent((int) $s['deflected'], (int) $s['resolved']);
        $lines[] = $pct === null
            ? 'AI deflection: nothing resolved this week'
            : 'AI deflection: ' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% (' . $s['deflected'] . ' of ' . $s['resolved'] . ' resolved without a person)';

        $lines[] = '';
        $lines[] = 'Details: ' . $link;
        $lines[] = '';
        $lines[] = brand_name();

        return implode("\n", $lines);
    }

    // stats for the 7 days before now, company wide and per department name in lower case
    public function gather(\DateTimeImmutable $now): array
    {
        $db    = \Config\Database::connect();
        $end   = $now->getTimestamp();
        $start = $end - 7 * 86400;
        $since = date('Y-m-d H:i:s', $start);

        $rows = $db->table('tickets')
            ->select('id, requester, requester_email, submitting_department, responsible_department, category, status, created_at, updated_at')
            ->groupStart()
                ->where('created_at >=', $since)
                ->orWhere('updated_at >=', $since)
                ->orWhereIn('status', TicketModel::OPEN_STATUSES)
            ->groupEnd()
            ->get()->getResultArray();

        $tickets = [];
        foreach ($rows as $r) {
            $tickets[(string) $r['id']] = $r + ['dept' => $this->deptOf($r)];
        }

        $meta = [];
        foreach (array_chunk(array_keys($tickets), self::CHUNK) as $chunk) {
            $meta += (new TicketMetaModel())->forTickets(array_map('strval', $chunk));
        }

        $company = self::emptyStats();
        $depts   = [];
        $done    = [];

        foreach ($tickets as $id => $t) {
            $m       = $meta[$id] ?? [];
            $status  = (string) ($t['status'] ?: 'New');
            $created = strtotime((string) $t['created_at']) ?: $end;
            $key     = $t['dept'];
            $add     = [];

            if ($created >= $start && $created <= $end) {
                $add['new']      = 1;
                $add['category'] = trim((string) $t['category']) ?: 'Uncategorized';
            }

            if (in_array($status, TicketModel::OPEN_STATUSES, true)) {
                $add['open']   = 1;
                $add['bucket'] = self::bucket((int) floor(($end - $created) / 86400));
            }

            if (in_array($status, self::DONE, true)) {
                $resolvedTs = strtotime((string) ($m['resolved_at'] ?? '')) ?: (strtotime((string) $t['updated_at']) ?: 0);

                if ($resolvedTs >= $start && $resolvedTs <= $end) {
                    $add['resolved'] = 1;
                    $done[$id] = ['reqEmail' => strtolower(trim((string) $t['requester_email'])), 'reqName' => trim((string) $t['requester'])];

                    $due = strtotime((string) ($m['due_at'] ?? '')) ?: 0;
                    if ($due > 0) {
                        $add[$resolvedTs <= $due ? 'slaMet' : 'slaBreached'] = 1;
                    }
                }
            }

            self::add($company, $add);
            $depts[$key] ??= self::emptyStats();
            self::add($depts[$key], $add);
        }

        $byEmail = [];
        foreach ($db->table('users')->select('id, email')->get()->getResultArray() as $u) {
            $byEmail[strtolower(trim((string) $u['email']))] = (int) $u['id'];
        }

        foreach (AiDeflection::deflectedIds($done, $byEmail) as $id) {
            $company['deflected']++;
            $depts[$tickets[$id]['dept']]['deflected']++;
        }

        foreach ($this->ratings($since, $tickets) as [$dept, $rating]) {
            $company['csat'][] = $rating;
            $depts[$dept] ??= self::emptyStats();
            $depts[$dept]['csat'][] = $rating;
        }

        return ['company' => $company, 'departments' => $depts];
    }

    // builds every email, each item has user, subject and body
    public function build(\DateTimeImmutable $now): array
    {
        $stats = $this->gather($now);
        $local = $now->setTimezone(new \DateTimeZone(self::TZ));
        $from  = $local->modify('-7 days')->format('j M Y');
        $to    = $local->format('j M Y');
        $base  = rtrim((string) (env('app_baseURL') ?: env('APP_URL') ?: config('App')->baseURL), '/');
        $users = [];

        foreach ((new UserModel())->where('is_active', 1)->findAll() as $u) {
            $users[(int) $u['id']] = $u;
        }

        $mails = [];

        foreach ($users as $u) {
            if ($u['role'] === UserModel::ROLE_SUPERADMIN) {
                $mails[] = [
                    'user'    => $u,
                    'subject' => 'Weekly helpdesk summary: all departments',
                    'body'    => self::text($stats['company'], 'all departments', $from, $to, $base . '/reports'),
                ];
            }
        }

        foreach ((new DepartmentModel())->active() as $d) {
            $key = strtolower(trim((string) $d['name']));
            $ids = array_unique(array_filter([(int) ($d['supervisor_id'] ?? 0), (int) ($d['team_leader_id'] ?? 0)]));

            foreach ($ids as $uid) {
                if (! isset($users[$uid]) || ! in_array($users[$uid]['role'], [UserModel::ROLE_SUPERVISOR, UserModel::ROLE_TEAM_LEADER], true)) {
                    continue;
                }

                $mails[] = [
                    'user'    => $users[$uid],
                    'subject' => 'Weekly helpdesk summary: ' . $d['name'],
                    'body'    => self::text($stats['departments'][$key] ?? self::emptyStats(), $d['name'], $from, $to, $base . '/tickets?view=team'),
                ];
            }
        }

        return $mails;
    }

    private static function add(array &$s, array $add): void
    {
        foreach (['new', 'open', 'resolved', 'slaMet', 'slaBreached'] as $k) {
            $s[$k] += $add[$k] ?? 0;
        }
        if (isset($add['bucket'])) {
            $s['ageing'][$add['bucket']]++;
        }
        if (isset($add['category'])) {
            $s['categories'][$add['category']] = ($s['categories'][$add['category']] ?? 0) + 1;
        }
    }

    private function deptOf(array $row): string
    {
        $name = resolve_base_department(ticket_department([
            'Responsible Department' => $row['responsible_department'],
            'Submitting Department'  => $row['submitting_department'],
        ]));

        return strtolower(trim($name)) ?: 'unrouted';
    }

    // department and rating for each csat answer given since the cutoff
    private function ratings(string $since, array $tickets): array
    {
        $db = \Config\Database::connect();

        try {
            $rows = $db->table('ticket_feedback')->select('ticket_id, rating')
                ->where('created_at >=', $since)->where('rating >=', 1)->where('rating <=', 5)
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }

        $missing = array_values(array_diff(array_map(static fn ($r) => (string) $r['ticket_id'], $rows), array_map('strval', array_keys($tickets))));
        $extra   = [];

        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach ($db->table('tickets')->select('id, submitting_department, responsible_department')->whereIn('id', $chunk)->get()->getResultArray() as $r) {
                $extra[(string) $r['id']] = $this->deptOf($r);
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $id    = (string) $r['ticket_id'];
            $out[] = [$tickets[$id]['dept'] ?? ($extra[$id] ?? 'unrouted'), (int) $r['rating']];
        }

        return $out;
    }
}
