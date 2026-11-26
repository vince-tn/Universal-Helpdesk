<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\TicketMetaModel;
use App\Models\TicketModel;

// shared steps after a ticket or reply is saved, used by the web form and email intake
class TicketIntake
{
    // category, flags, priority, sla, activity, approvals and the created notice
    public function afterCreate(string $id, array $in): array
    {
        $tickets = new TicketModel();
        $metas   = new TicketMetaModel();

        $department = (string) ($in['department'] ?? '');
        $role       = (string) ($in['role'] ?? '');
        $service    = $in['service'] ?? null;
        $answers    = $in['answers'] ?? [];
        $own        = trim((string) ($in['own_department'] ?? ''));
        $people     = (string) ($in['people'] ?? '');
        $stopped    = (string) ($in['stopped'] ?? '');
        $neededBy   = trim((string) ($in['needed_by'] ?? ''));
        $actor      = $in['actor'] ?? null;
        $for        = strtolower(trim((string) ($in['requested_for'] ?? '')));

        $category = $service['category'] ?? 'Common Request';

        $tickets->update((int) $id, [
            'requested_for'        => $for !== '' ? mb_substr($for, 0, 190) : null,
            'requester_department' => $own !== '' ? mb_substr($own, 0, 100) : null,
            'service_key'          => $service['service_key'] ?? null,
            'service_fields'       => $answers === [] ? null : json_encode($answers),
            'is_confidential'      => self::isConfidential($department, $role) ? 1 : 0,
            'category'             => $category,
        ]);

        $priority = self::priorityFrom($people, $stopped);

        $metas->firstOrCreate($id);
        $metas->updateForTicket($id, array_filter([
            'priority'        => $priority,
            'people_affected' => in_array($people, ['me', 'team', 'many'], true) ? $people : null,
            'work_stopped'    => $stopped === 'yes' ? 1 : 0,
            'needed_by'       => is_ymd_date($neededBy) ? $neededBy : null,
        ], static fn ($v) => $v !== null));

        (new Sla())->stamp($id, date('Y-m-d H:i:s'), $category, $priority ?? 'Medium');
        Activity::ticket($id, 'created', null, $department . ($role !== '' ? ' / ' . $role : ''), $actor);

        $fields = $tickets->getTicket($id)['fields'] ?? [];

        if ($service !== null && (int) $service['requires_approval'] === 1) {
            (new Approvals())->start($id, $fields, (string) ($service['approval_chain'] ?: 'supervisor'));
        }

        $skipped = ($in['watchers'] ?? []) === [] ? [] : Watchers::add($id, $fields, $in['watchers'], $actor);

        (new AutoAssign())->run($id, $fields);
        (new Duplicates())->detect($id, $fields);

        (new Notifier())->created($id, $fields);

        $fields['_skipped_watchers'] = $skipped;

        return $fields;
    }

    // reopen or resume on a requester reply, first response on a staff reply, then notify, returns mentioned users
    public function afterReply(string $id, array $fields, array $user, string $body, bool $internal): array
    {
        $status      = (string) ($fields['Status'] ?? 'New');
        $isRequester = Watchers::isRequesterSide($user, $fields);

        if ($internal) {
            Activity::ticket($id, 'note', null, null, $user);
        } elseif ($isRequester && $status === 'Resolved') {
            $this->changeStatus($id, $fields, $status, 'Reopened', $user);
        } elseif ($isRequester && $status === 'Awaiting Requester') {
            $this->changeStatus($id, $fields, $status, 'In Progress', $user);
        } elseif (! $isRequester && Org::canManageTicket($user, $fields)) {
            (new Sla())->markFirstResponse($id);
        }

        (new Notifier())->replied($id, $fields, $user, $internal);

        return (new Mentions())->notify(
            $body,
            $user,
            $id,
            (string) ($fields['Request Title'] ?? $fields['Title'] ?? 'a ticket'),
            $internal
                ? array_values(array_filter(Org::ticketAudience($fields), static fn ($u) => ($u['role'] ?? '') !== \App\Models\UserModel::ROLE_REQUESTER))
                : Org::ticketAudience($fields)
        );
    }

    public function changeStatus(string $id, array $fields, string $from, string $to, ?array $actor = null): void
    {
        (new TicketModel())->updateStatus($id, $to);
        (new Sla())->onStatusChange($id, $from, $to);
        Activity::ticket($id, 'status', $from, $to, $actor);
        (new Notifier())->statusChanged($id, $fields, $from, $to);

        if ($to === 'Closed') {
            $this->sendSurvey($id, $fields);
        }
    }

    public function sendSurvey(string $id, array $fields): void
    {
        $db = \Config\Database::connect();
        if ($db->table('ticket_feedback')->where('ticket_id', $id)->countAllResults() > 0) {
            return;
        }
        $token = bin2hex(random_bytes(16));
        $db->table('ticket_feedback')->insert(['ticket_id' => $id, 'rating' => 0, 'token' => $token, 'created_at' => date('Y-m-d H:i:s')]);
        (new Notifier())->survey($id, $fields, $token);
    }

    // impact and urgency matrix from the two intake questions
    public static function priorityFrom(string $people, string $stopped): ?string
    {
        if (! in_array($people, ['me', 'team', 'many'], true) || ! in_array($stopped, ['yes', 'no'], true)) {
            return null;
        }

        return [
            'me'   => ['no' => 'Low', 'yes' => 'Medium'],
            'team' => ['no' => 'Medium', 'yes' => 'High'],
            'many' => ['no' => 'High', 'yes' => 'Urgent'],
        ][$people][$stopped];
    }

    public static function isConfidential(string $department, string $role): bool
    {
        foreach ((new DepartmentModel())->withRoles() as $d) {
            if (strcasecmp($d['name'], $department) !== 0) {
                continue;
            }
            if ((int) ($d['is_confidential'] ?? 0) === 1) {
                return true;
            }
            foreach ($d['roles'] as $r) {
                if ($role !== '' && strcasecmp($r['name'], $role) === 0 && (int) ($r['is_confidential'] ?? 0) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
