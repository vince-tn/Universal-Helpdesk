<?php

namespace App\Controllers;

use App\Libraries\ExportGuard;
use App\Models\TicketMetaModel;
use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;

class ReportsController extends BaseController
{
    public const STATUSES = [
        'New', 'In Progress', 'Awaiting Requester', 'On Hold', 'Pending Approval',
        'Resolved', 'Reopened', 'Closed', 'Cancelled',
    ];

    private const DONE      = ['Resolved', 'Closed'];
    private const NOT_OPEN  = ['Resolved', 'Closed', 'Cancelled'];
    private const RANGES    = ['7', '30', '90', 'custom'];
    private const CHUNK     = 500;
    private const TABLE_TOP = 15;

    protected TicketMetaModel $metaModel;
    protected BaseConnection $db;

    public function __construct()
    {
        $this->metaModel = new TicketMetaModel();
        $this->db        = \Config\Database::connect();
    }

    private function dateParam(string $name): string
    {
        $value = trim((string) ($this->request->getGet($name) ?? ''));

        return is_ymd_date($value) ? $value : '';
    }

    private function filters(): array
    {
        $raw   = $this->request->getGet('range');
        $range = in_array((string) $raw, self::RANGES, true) ? (string) $raw : '30';
        $from  = $this->dateParam('date_from');
        $to    = $this->dateParam('date_to');
        $today = date('Y-m-d');

        // old links with dates but no range still mean a custom range
        if ($raw === null && ($from !== '' || $to !== '')) {
            $range = 'custom';
        }

        if ($range === 'custom') {
            $to   = $to !== '' ? $to : $today;
            $from = $from !== '' ? $from : date('Y-m-d', strtotime($to . ' -29 days'));

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
        } else {
            $to   = $today;
            $from = date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days'));
        }

        return [
            'range'      => $range,
            'dateFrom'   => $from,
            'dateTo'     => $to,
            'department' => trim((string) ($this->request->getGet('department') ?? '')),
        ];
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return $this->db->fieldExists($column, $table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return $this->db->tableExists($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function ts(mixed $value): ?int
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000')) {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }

    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private static function average(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    private static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function metaFor(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $out += $this->metaModel->forTickets($chunk);
        }

        return $out;
    }

    // earliest staff reply that is not the requester, per ticket
    private function firstStaffReplies(array $ids, array $tickets, array $usersById): array
    {
        $first = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->table('ticket_messages')
                ->select('ticket_id, user_id, author_name, author_role, created_at')
                ->whereIn('ticket_id', $chunk)
                ->whereIn('author_role', UserModel::STAFF_ROLES)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($rows as $m) {
                $id = (string) $m['ticket_id'];

                if (isset($first[$id]) || ! isset($tickets[$id])) {
                    continue;
                }

                $t           = $tickets[$id];
                $authorEmail = strtolower((string) ($usersById[(int) ($m['user_id'] ?? 0)]['email'] ?? ''));
                $isRequester = ($authorEmail !== '' && $authorEmail === $t['reqEmail'])
                    || ($t['reqName'] !== '' && strtolower(trim((string) $m['author_name'])) === $t['reqName']);

                $ts = self::ts($m['created_at']);
                if (! $isRequester && $ts !== null) {
                    $first[$id] = $ts;
                }
            }
        }

        return $first;
    }

    private function feedbackFor(array $ids): array
    {
        $ratings = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->table('ticket_feedback')
                ->select('ticket_id, rating')
                ->whereIn('ticket_id', $chunk)
                ->get()
                ->getResultArray();

            foreach ($rows as $r) {
                $rating = (int) $r['rating'];
                if ($rating >= 1 && $rating <= 5) {
                    $ratings[(string) $r['ticket_id']][] = $rating;
                }
            }
        }

        return $ratings;
    }

    private static function emptyGroup(string $name): array
    {
        return [
            'name' => $name, 'tickets' => 0, 'open' => 0, 'resolved' => 0,
            'fr' => [], 'res' => [], 'met' => 0, 'breached' => 0,
        ];
    }

    private static function finishGroups(array $groups): array
    {
        $rows = array_map(static fn ($g) => [
            'name'     => $g['name'],
            'tickets'  => $g['tickets'],
            'open'     => $g['open'],
            'resolved' => $g['resolved'],
            'avgFirst' => self::average($g['fr']),
            'avgRes'   => self::average($g['res']),
            'slaMet'   => self::pct($g['met'], $g['met'] + $g['breached']),
        ], array_values($groups));

        usort($rows, static fn ($a, $b) => [$b['tickets'], $a['name']] <=> [$a['tickets'], $b['name']]);

        return $rows;
    }

    private function compute(array $filters): array
    {
        $now   = time();
        $today = date('Y-m-d');

        $cols = [
            'first_response_at'  => $this->hasColumn('ticket_meta', 'first_response_at'),
            'due_at'             => $this->hasColumn('ticket_meta', 'due_at'),
            'response_due_at'    => $this->hasColumn('ticket_meta', 'response_due_at'),
            'resolved_at'        => $this->hasColumn('ticket_meta', 'resolved_at'),
            'reopened_count'     => $this->hasColumn('ticket_meta', 'reopened_count'),
            'sla_paused_minutes' => $this->hasColumn('ticket_meta', 'sla_paused_minutes'),
        ];
        $hasFeedback = $this->hasTable('ticket_feedback');
        $hasCompany  = $this->hasColumn('users', 'company');

        $userCols  = 'id, name, email, department' . ($hasCompany ? ', company' : '');
        $usersById = [];
        $byEmail   = [];
        foreach ($this->db->table('users')->select($userCols)->get()->getResultArray() as $u) {
            $usersById[(int) $u['id']] = $u;
            $byEmail[strtolower(trim((string) $u['email']))] = $u;
        }

        $raw = $this->db->table('tickets')
            ->select('id, requester, requester_email, submitting_department, responsible_department, responsible_role, category, status, created_at, updated_at')
            ->where('created_at >=', $filters['dateFrom'] . ' 00:00:00')
            ->where('created_at <=', $filters['dateTo'] . ' 23:59:59')
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        $tickets = [];
        foreach ($raw as $r) {
            $dept = resolve_base_department(ticket_department([
                'Responsible Department' => $r['responsible_department'],
                'Submitting Department'  => $r['submitting_department'],
            ]));

            if ($filters['department'] !== '' && $dept !== $filters['department']) {
                continue;
            }

            $email = strtolower(trim((string) $r['requester_email']));

            $tickets[(string) $r['id']] = [
                'status'   => (string) ($r['status'] ?: 'New'),
                'created'  => self::ts($r['created_at']) ?? $now,
                'updated'  => self::ts($r['updated_at']),
                'dept'     => $dept !== '' ? $dept : 'Unrouted',
                'role'     => trim((string) $r['responsible_role']) ?: 'No sub-team set',
                'reqDept'  => trim((string) $r['submitting_department']) ?: 'Not given',
                'company'  => trim((string) ($byEmail[$email]['company'] ?? '')) ?: 'Unknown company',
                'category' => trim((string) $r['category']) ?: 'Uncategorized',
                'reqEmail' => $email,
                'reqName'  => strtolower(trim((string) $r['requester'])),
            ];
        }

        $ids  = array_map('strval', array_keys($tickets));
        $meta = $this->metaFor($ids);

        $needFallback = array_values(array_filter(
            $ids,
            static fn ($id) => ! $cols['first_response_at'] || empty($meta[$id]['first_response_at'])
        ));
        $fallbackFirst = $needFallback === [] ? [] : $this->firstStaffReplies($needFallback, $tickets, $usersById);
        $ratings       = $hasFeedback && $ids !== [] ? $this->feedbackFor($ids) : [];

        $statusCounts   = array_fill_keys(self::STATUSES, 0);
        $priorityCounts = array_fill_keys(priorities(), 0);
        $categoryCounts = [];
        $ageing         = ['0 to 2 days' => 0, '3 to 5 days' => 0, '6+ days' => 0];
        $oldestOpen     = null;
        $groupKeys      = ['dept' => [], 'role' => [], 'reqDept' => [], 'company' => [], 'agent' => []];

        $fr = $res = [];
        $open = $resolved = $overdue = $unassignedOpen = $reopened = 0;
        $slaMet = $slaBreached = $slaPending = 0;
        $respMet = $respBreached = 0;
        $csat = [];

        $volume = [];
        $volStart = max(strtotime($filters['dateFrom']), strtotime($filters['dateTo'] . ' -91 days'));
        for ($d = $volStart; date('Y-m-d', $d) <= $filters['dateTo']; $d = strtotime('+1 day', $d)) {
            $volume[date('Y-m-d', $d)] = 0;
        }

        foreach ($tickets as $id => $t) {
            $m      = $meta[$id] ?? [];
            $status = $t['status'];
            $isOpen = ! in_array($status, self::NOT_OPEN, true);
            $isDone = in_array($status, self::DONE, true);

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            $priority = (string) ($m['priority'] ?? 'Medium');
            $priorityCounts[$priority] = ($priorityCounts[$priority] ?? 0) + 1;
            $categoryCounts[$t['category']] = ($categoryCounts[$t['category']] ?? 0) + 1;

            $day = date('Y-m-d', $t['created']);
            if (isset($volume[$day])) {
                $volume[$day]++;
            }

            $firstTs = ($cols['first_response_at'] ? self::ts($m['first_response_at'] ?? null) : null) ?? ($fallbackFirst[$id] ?? null);
            $frMin   = $firstTs !== null && $firstTs >= $t['created'] ? ($firstTs - $t['created']) / 60 : null;

            $resolvedTs = null;
            if ($isDone) {
                $resolvedTs = ($cols['resolved_at'] ? self::ts($m['resolved_at'] ?? null) : null) ?? $t['updated'];
            }
            $paused = $cols['sla_paused_minutes'] ? max(0, (int) ($m['sla_paused_minutes'] ?? 0)) : 0;
            $resMin = $resolvedTs !== null && $resolvedTs >= $t['created']
                ? max(0, ($resolvedTs - $t['created']) / 60 - $paused)
                : null;

            $sla = null;
            $due = $cols['due_at'] ? self::ts($m['due_at'] ?? null) : null;
            if ($due !== null && $status !== 'Cancelled') {
                if ($isDone && $resolvedTs !== null) {
                    $sla = $resolvedTs <= $due ? 'met' : 'breached';
                } elseif ($isOpen) {
                    $sla = $now > $due ? 'breached' : 'pending';
                }
            }
            $slaMet      += $sla === 'met' ? 1 : 0;
            $slaBreached += $sla === 'breached' ? 1 : 0;
            $slaPending  += $sla === 'pending' ? 1 : 0;

            $respDue = $cols['response_due_at'] ? self::ts($m['response_due_at'] ?? null) : null;
            if ($respDue !== null && $status !== 'Cancelled') {
                if ($firstTs !== null && $firstTs <= $respDue) {
                    $respMet++;
                } elseif ($firstTs !== null || $now > $respDue) {
                    $respBreached++;
                }
            }

            if ($frMin !== null) {
                $fr[] = $frMin;
            }
            if ($resMin !== null) {
                $res[] = $resMin;
            }

            if ($isOpen) {
                $open++;
                $ageDays = (int) floor(($now - $t['created']) / 86400);
                $bucket  = $ageDays <= 2 ? '0 to 2 days' : ($ageDays <= 5 ? '3 to 5 days' : '6+ days');
                $ageing[$bucket]++;
                $oldestOpen = $oldestOpen === null ? $ageDays : max($oldestOpen, $ageDays);

                $legacyDue = (string) ($m['due_date'] ?? '');
                if (($due !== null && $now > $due) || ($due === null && $legacyDue !== '' && $legacyDue < $today)) {
                    $overdue++;
                }
                if (empty($m['assigned_to'])) {
                    $unassignedOpen++;
                }
            }
            if ($isDone) {
                $resolved++;
            }

            $wasReopened = $cols['reopened_count'] ? (int) ($m['reopened_count'] ?? 0) > 0 : $status === 'Reopened';
            $reopened   += $wasReopened ? 1 : 0;

            foreach ($ratings[$id] ?? [] as $rating) {
                $csat[] = $rating;
            }

            $agentId   = (int) ($m['assigned_to'] ?? 0);
            $agentName = $agentId > 0 ? (string) ($usersById[$agentId]['name'] ?? 'Removed user #' . $agentId) : 'Unassigned';

            $keys = [
                'dept'    => $t['dept'],
                'role'    => $t['role'],
                'reqDept' => $t['reqDept'],
                'company' => $t['company'],
                'agent'   => $agentName,
            ];
            foreach ($keys as $dim => $name) {
                $g = $groupKeys[$dim][$name] ?? self::emptyGroup($name);
                $g['tickets']++;
                $g['open']     += $isOpen ? 1 : 0;
                $g['resolved'] += $isDone ? 1 : 0;
                if ($frMin !== null) {
                    $g['fr'][] = $frMin;
                }
                if ($resMin !== null) {
                    $g['res'][] = $resMin;
                }
                $g['met']      += $sla === 'met' ? 1 : 0;
                $g['breached'] += $sla === 'breached' ? 1 : 0;
                $groupKeys[$dim][$name] = $g;
            }
        }

        arsort($categoryCounts);
        $total = count($tickets);

        return $filters + [
            'total'          => $total,
            'openCount'      => $open,
            'resolvedCount'  => $resolved,
            'overdueCount'   => $overdue,
            'unassignedOpen' => $unassignedOpen,
            'firstMedian'    => self::median($fr),
            'firstAverage'   => self::average($fr),
            'firstCount'     => count($fr),
            'resAverage'     => self::average($res),
            'resCount'       => count($res),
            'slaOn'          => $cols['due_at'] && ($slaMet + $slaBreached + $slaPending) > 0,
            'slaMetPct'      => self::pct($slaMet, $slaMet + $slaBreached),
            'slaBreachedPct' => self::pct($slaBreached, $slaMet + $slaBreached),
            'slaMet'         => $slaMet,
            'slaBreached'    => $slaBreached,
            'slaPending'     => $slaPending,
            'respSlaPct'     => self::pct($respMet, $respMet + $respBreached),
            'reopenRate'     => self::pct($reopened, $total),
            'reopenCount'    => $reopened,
            'reopenTracked'  => $cols['reopened_count'],
            'csatAverage'    => $csat === [] ? null : round(array_sum($csat) / count($csat), 2),
            'csatCount'      => count($csat),
            'csatOn'         => $hasFeedback,
            'ageing'         => $ageing,
            'oldestOpen'     => $oldestOpen,
            'statusCounts'   => $statusCounts,
            'priorityCounts' => $priorityCounts,
            'topCategories'  => array_slice($categoryCounts, 0, 6, true),
            'volumeByDay'    => $volume,
            'volumeTrimmed'  => strtotime($filters['dateFrom']) < $volStart,
            'breakdowns'     => [
                'dept'    => ['title' => 'By responsible department', 'rows' => self::finishGroups($groupKeys['dept'])],
                'role'    => ['title' => 'By sub-team', 'rows' => self::finishGroups($groupKeys['role'])],
                'reqDept' => ['title' => 'By requester department', 'rows' => self::finishGroups($groupKeys['reqDept'])],
                'company' => ['title' => 'By company', 'rows' => self::finishGroups($groupKeys['company'])],
                'agent'   => ['title' => 'By agent', 'rows' => self::finishGroups($groupKeys['agent'])],
            ],
        ];
    }

    public function index()
    {
        $data = $this->compute($this->filters());

        $data['tableTop']    = self::TABLE_TOP;
        $data['canExport']   = ExportGuard::allowed(current_user());
        $data['queryString'] = http_build_query(array_filter([
            'range'      => $data['range'],
            'date_from'  => $data['range'] === 'custom' ? $data['dateFrom'] : '',
            'date_to'    => $data['range'] === 'custom' ? $data['dateTo'] : '',
            'department' => $data['department'],
        ]));

        return view('reports/index', $data);
    }

    private static function hours(?float $minutes): string
    {
        return $minutes === null ? '' : (string) round($minutes / 60, 1);
    }

    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    public function export()
    {
        $user = current_user();

        if (! ExportGuard::allowed($user)) {
            return redirect()->to('/reports')->with('error', 'Only super admins and supervisors can export report data.');
        }

        $d     = $this->compute($this->filters());
        $range = date('j M Y', strtotime($d['dateFrom'])) . ' to ' . date('j M Y', strtotime($d['dateTo']));
        $sla   = static fn (?float $p) => $p === null ? '' : $p;

        $lines = [
            ['Report', 'Helpdesk summary'],
            ['Range', $range],
            ['Department', $d['department'] !== '' ? $d['department'] : 'All departments'],
            ['Generated', date('j M Y g:i A')],
            [],
            ['Metric', 'Value'],
            ['Tickets created', $d['total']],
            ['Open', $d['openCount']],
            ['Resolved or closed', $d['resolvedCount']],
            ['Overdue (open)', $d['overdueCount']],
            ['Unassigned (open)', $d['unassignedOpen']],
            ['First response median (hours)', self::hours($d['firstMedian'])],
            ['First response average (hours)', self::hours($d['firstAverage'])],
            ['Average resolution (hours)', self::hours($d['resAverage'])],
            ['SLA met %', $d['slaOn'] ? $sla($d['slaMetPct']) : 'SLA data starts once SLAs are on'],
            ['SLA breached %', $d['slaOn'] ? $sla($d['slaBreachedPct']) : 'SLA data starts once SLAs are on'],
            ['Reopen rate %', $sla($d['reopenRate'])],
            ['CSAT average (1 to 5)', $d['csatAverage'] ?? ''],
            ['CSAT responses', $d['csatCount']],
        ];
        foreach ($d['ageing'] as $bucket => $count) {
            $lines[] = ['Open backlog ' . $bucket, $count];
        }

        foreach ($d['breakdowns'] as $b) {
            $lines[] = [];
            $lines[] = [$b['title'], 'Tickets', 'Open', 'Resolved', 'Avg first response (hours)', 'Avg resolution (hours)', 'SLA met %'];
            foreach ($b['rows'] as $r) {
                $lines[] = [$r['name'], $r['tickets'], $r['open'], $r['resolved'], self::hours($r['avgFirst']), self::hours($r['avgRes']), $sla($r['slaMet'])];
            }
        }

        $out = fopen('php://temp', 'w+');
        foreach ($lines as $line) {
            fputcsv($out, array_map(static fn ($v) => self::cell($v), $line));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        ExportGuard::log($user, 'report summary export, format csv, ' . count($lines) . ' rows, ' . $range
            . ($d['department'] !== '' ? ', ' . $d['department'] : ''));

        return $this->response
            ->setContentType('text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="report_summary_' . $d['dateFrom'] . '_to_' . $d['dateTo'] . '.csv"')
            ->setBody($csv);
    }
}
