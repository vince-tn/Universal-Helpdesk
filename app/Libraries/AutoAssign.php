<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\TicketMetaModel;
use App\Models\TicketModel;
use App\Models\UserModel;

// assigns unowned tickets from the department setting
class AutoAssign
{
    public const ACTOR = ['id' => null, 'name' => 'Auto-assign'];

    // assigns the ticket when nobody holds it, returns the new assignee id
    public function run(string $ticketId, array $fields): ?int
    {
        try {
            $meta = (new TicketMetaModel())->firstOrCreate($ticketId);

            if (! empty($meta['assigned_to'])) {
                return null;
            }

            $department = $this->department($fields);

            if ($department === null) {
                return null;
            }

            $userId = match ((string) ($department['assignment_mode'] ?? 'none')) {
                'round_robin'    => $this->roundRobin($department, $fields),
                'default_person' => $this->defaultPerson($department, $fields),
                default          => null,
            };

            if ($userId === null) {
                return null;
            }

            $user = (new UserModel())->find($userId);

            (new TicketMetaModel())->updateForTicket($ticketId, ['assigned_to' => $userId]);
            self::touch($userId);
            Activity::ticket($ticketId, 'assigned', null, (string) ($user['name'] ?? ''), self::ACTOR);
            (new Notifier())->assigned($ticketId, $fields, $userId);

            return $userId;
        } catch (\Throwable $e) {
            log_message('error', 'auto assign failed on #{id}: {m}', ['id' => $ticketId, 'm' => $e->getMessage()]);

            return null;
        }
    }

    // after the assistant reroutes, swaps an auto pick that is not in the new team
    public function afterReroute(string $ticketId, array $fields): ?int
    {
        try {
            $holder = (int) ((new TicketMetaModel())->firstOrCreate($ticketId)['assigned_to'] ?? 0);

            if ($holder > 0) {
                $last = \Config\Database::connect()->table('ticket_events')
                    ->where('ticket_id', $ticketId)->where('event', 'assigned')
                    ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

                $inTeam = in_array($holder, array_map('intval', array_column(Org::assignableStaff($fields), 'id')), true);

                if ($inTeam || ($last['actor_name'] ?? '') !== self::ACTOR['name']) {
                    return null;
                }

                (new TicketMetaModel())->updateForTicket($ticketId, ['assigned_to' => null]);
                Activity::ticket($ticketId, 'assigned', null, '', self::ACTOR);
            }
        } catch (\Throwable $e) {
            log_message('error', 'auto assign reroute failed on #{id}: {m}', ['id' => $ticketId, 'm' => $e->getMessage()]);

            return null;
        }

        return $this->run($ticketId, $fields);
    }

    // stamps the last assignment time used for round robin ties
    public static function touch(int $userId): void
    {
        try {
            \Config\Database::connect()->table('users')->where('id', $userId)->update(['last_assigned_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            log_message('error', 'last assigned stamp failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    // fewest open tickets wins, ties go to the longest since last assignment
    public static function pick(array $candidates): ?int
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function ($a, $b) {
            $byOpen = (int) ($a['open'] ?? 0) <=> (int) ($b['open'] ?? 0);

            if ($byOpen !== 0) {
                return $byOpen;
            }

            $la = (string) ($a['last'] ?? '');
            $lb = (string) ($b['last'] ?? '');

            if ($la !== $lb) {
                if ($la === '') {
                    return -1;
                }
                if ($lb === '') {
                    return 1;
                }

                return strtotime($la) <=> strtotime($lb);
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });

        return (int) $candidates[0]['id'];
    }

    private function department(array $fields): ?array
    {
        $name = resolve_base_department(ticket_department($fields));

        return $name === '' ? null : (new DepartmentModel())->findByName($name);
    }

    private function roundRobin(array $department, array $fields): ?int
    {
        $agents = (new UserModel())
            ->where('role', UserModel::ROLE_AGENT)
            ->where('is_active', 1)
            ->where('department', (string) $department['name'])
            ->findAll();

        $agents = array_values(array_filter($agents, fn ($u) => $this->eligible($u, $fields)));

        if ($agents === []) {
            return null;
        }

        $ids  = array_map(static fn ($u) => (int) $u['id'], $agents);
        $open = $this->openCounts($ids);

        return self::pick(array_map(static fn ($u) => [
            'id'   => (int) $u['id'],
            'open' => $open[(int) $u['id']] ?? 0,
            'last' => $u['last_assigned_at'] ?? null,
        ], $agents));
    }

    private function defaultPerson(array $department, array $fields): ?int
    {
        $id = (int) ($department['default_assignee_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        $user = (new UserModel())->find($id);

        return $user !== null && $this->eligible($user, $fields) ? $id : null;
    }

    // active staff only, confidential tickets stay inside the circle
    private function eligible(array $user, array $fields): bool
    {
        if ((int) ($user['is_active'] ?? 0) !== 1 || UserModel::isExpired($user)) {
            return false;
        }

        if (! in_array($user['role'] ?? '', [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR], true)) {
            return false;
        }

        return (int) ($fields['Confidential'] ?? 0) !== 1 || Org::inConfidentialCircle($user, $fields);
    }

    private function openCounts(array $userIds): array
    {
        $rows = \Config\Database::connect()->table('ticket_meta m')
            ->select('m.assigned_to, COUNT(*) AS total')
            ->join('tickets t', 't.id = m.ticket_id')
            ->whereIn('m.assigned_to', $userIds)
            ->whereIn('t.status', TicketModel::OPEN_STATUSES)
            ->groupBy('m.assigned_to')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['assigned_to']] = (int) $row['total'];
        }

        return $out;
    }
}
