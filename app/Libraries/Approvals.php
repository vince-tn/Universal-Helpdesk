<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\TicketModel;
use App\Models\UserModel;

// sign-off steps per request type before work starts
class Approvals
{
    public function start(string $ticketId, array $fields, string $chain): void
    {
        $steps = array_values(array_filter(array_map('trim', explode(',', $chain))));

        if ($steps === []) {
            return;
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        foreach ($steps as $i => $role) {
            $db->table('ticket_approvals')->insert([
                'ticket_id'     => $ticketId,
                'step'          => $i + 1,
                'approver_role' => $role,
                'approver_id'   => $this->namedApprover($fields, $role),
                'status'        => 'pending',
                'created_at'    => $now,
            ]);
        }

        (new TicketModel())->update((int) $ticketId, ['approval_state' => 'pending', 'status' => 'Pending Approval']);
        (new Sla())->onStatusChange($ticketId, 'New', 'Pending Approval');
        Activity::ticket($ticketId, 'approval', null, 'sent for approval: ' . implode(' then ', array_map('role_label', $steps)));

        (new Notifier())->approvalNeeded($ticketId, $fields, $this->approversFor($fields, $steps[0]));
    }

    public function forTicket(string $ticketId): array
    {
        return \Config\Database::connect()->table('ticket_approvals')
            ->where('ticket_id', $ticketId)->orderBy('step', 'ASC')->get()->getResultArray();
    }

    public function current(string $ticketId): ?array
    {
        foreach ($this->forTicket($ticketId) as $step) {
            if ($step['status'] === 'pending') {
                return $step;
            }
        }

        return null;
    }

    public function canDecide(?array $user, array $fields, array $step): bool
    {
        if ($user === null) {
            return false;
        }
        if (($user['role'] ?? '') === UserModel::ROLE_SUPERADMIN) {
            return true;
        }
        if ((int) ($step['approver_id'] ?? 0) > 0) {
            return (int) $step['approver_id'] === (int) $user['id'];
        }

        return ($user['role'] ?? '') === $step['approver_role']
            && strcasecmp(trim((string) $user['department']), resolve_base_department(ticket_department($fields))) === 0;
    }

    // returns the new ticket status
    public function decide(string $ticketId, array $fields, array $user, bool $approve, string $note): ?string
    {
        $step = $this->current($ticketId);

        if ($step === null || ($fields['Status'] ?? '') !== 'Pending Approval' || ! $this->canDecide($user, $fields, $step)) {
            return null;
        }

        $db = \Config\Database::connect();
        $db->table('ticket_approvals')->where('id', $step['id'])->where('status', 'pending')->update([
            'status'     => $approve ? 'approved' : 'rejected',
            'decided_by' => $user['id'],
            'decided_at' => date('Y-m-d H:i:s'),
            'note'       => mb_substr($note, 0, 500) ?: null,
        ]);

        $label = role_label($step['approver_role']);
        Activity::ticket($ticketId, 'approval', null, ($approve ? 'approved' : 'rejected') . ' as ' . $label . ($note !== '' ? ': ' . $note : ''), $user);

        $tickets = new TicketModel();

        if ($db->affectedRows() !== 1) {
            return null;
        }

        if (! $approve) {
            $db->table('ticket_approvals')->where('ticket_id', $ticketId)->where('status', 'pending')->update(['status' => 'skipped']);
            $tickets->update((int) $ticketId, ['approval_state' => 'rejected', 'status' => 'Cancelled']);
            (new Sla())->onStatusChange($ticketId, 'Pending Approval', 'Cancelled');
            (new Notifier())->statusChanged($ticketId, $fields, 'Pending Approval', 'Cancelled');

            return 'Cancelled';
        }

        $next = $this->current($ticketId);

        if ($next !== null) {
            (new Notifier())->approvalNeeded($ticketId, $fields, $this->approversFor($fields, $next['approver_role'], (int) ($next['approver_id'] ?? 0)));

            return 'Pending Approval';
        }

        $tickets->update((int) $ticketId, ['approval_state' => 'approved', 'status' => 'New']);
        (new Sla())->onStatusChange($ticketId, 'Pending Approval', 'New');
        (new Notifier())->statusChanged($ticketId, $fields, 'Pending Approval', 'Approved, now with the team');

        return 'New';
    }

    private function namedApprover(array $fields, string $role): ?int
    {
        $department = (new DepartmentModel())->findByName(resolve_base_department(ticket_department($fields)));

        $id = match ($role) {
            'team_leader' => (int) ($department['team_leader_id'] ?? 0),
            'supervisor'  => (int) ($department['supervisor_id'] ?? 0),
            default       => 0,
        };

        return $id > 0 ? $id : null;
    }

    private function approversFor(array $fields, string $role, int $named = 0): array
    {
        $users = new UserModel();
        $named = $named ?: (int) $this->namedApprover($fields, $role);

        if ($named > 0) {
            $one = $users->find($named);

            return $one === null ? [] : [$one];
        }

        $department = resolve_base_department(ticket_department($fields));
        $found = $users->where('role', $role)->where('is_active', 1)->where('department', $department)->findAll();

        return $found !== [] ? $found : $users->where('role', UserModel::ROLE_SUPERADMIN)->where('is_active', 1)->findAll();
    }
}
