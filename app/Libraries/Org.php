<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\UserModel;

class Org
{
    public static function isSuperadmin(?array $user): bool
    {
        return ($user['role'] ?? '') === UserModel::ROLE_SUPERADMIN;
    }

    public static function isSupervisor(?array $user): bool
    {
        return ($user['role'] ?? '') === UserModel::ROLE_SUPERVISOR;
    }

    public static function isTeamLeader(?array $user): bool
    {
        return ($user['role'] ?? '') === UserModel::ROLE_TEAM_LEADER;
    }

    public static function canOpenAdmin(?array $user): bool
    {
        return in_array($user['role'] ?? '', [
            UserModel::ROLE_SUPERADMIN,
            UserModel::ROLE_SUPERVISOR,
            UserModel::ROLE_TEAM_LEADER,
        ], true);
    }

    public static function manageableDepartments(?array $user): array
    {
        $model = new DepartmentModel();

        if (self::isSuperadmin($user)) {
            return $model->withRoles();
        }

        if (self::isSupervisor($user)) {
            return array_values(array_filter(
                $model->withRoles(),
                static fn ($d) => (int) ($d['supervisor_id'] ?? 0) === (int) ($user['id'] ?? 0)
            ));
        }

        if (self::isTeamLeader($user)) {
            return array_values(array_filter(
                $model->withRoles(),
                static fn ($d) => (int) ($d['team_leader_id'] ?? 0) === (int) ($user['id'] ?? 0)
                    || strcasecmp((string) $d['name'], (string) ($user['department'] ?? '')) === 0
            ));
        }

        return [];
    }

    public static function knowledgeOnlyDepartment(?array $user): ?array
    {
        if ($user === null || self::canOpenAdmin($user)) {
            return null;
        }

        if ((int) ($user['can_edit_knowledge'] ?? 0) !== 1) {
            return null;
        }

        $own = trim((string) ($user['department'] ?? ''));

        return $own === '' ? null : (new DepartmentModel())->findByName($own);
    }

    public static function canEditKnowledge(?array $user, ?int $departmentId): bool
    {
        if (self::isSuperadmin($user)) {
            return true;
        }

        if ($departmentId === null) {
            return false;
        }

        if (self::canManageDepartment($user, $departmentId)) {
            return true;
        }

        $own = self::knowledgeOnlyDepartment($user);

        return $own !== null && (int) $own['id'] === $departmentId;
    }

    public static function canManageDepartment(?array $user, int $departmentId): bool
    {
        foreach (self::manageableDepartments($user) as $d) {
            if ((int) $d['id'] === $departmentId) {
                return true;
            }
        }

        return false;
    }

    public static function departmentNames(?array $user): array
    {
        return array_column(self::manageableDepartments($user), 'name');
    }

    private static array $ticketScopes = [];

    public static function ticketDepartments(?array $user): array
    {
        $key = implode('|', [$user['id'] ?? '', $user['role'] ?? '', $user['department'] ?? '']);

        if (isset(self::$ticketScopes[$key])) {
            return self::$ticketScopes[$key];
        }

        $own = trim((string) ($user['department'] ?? ''));

        $names = match ($user['role'] ?? '') {
            UserModel::ROLE_AGENT => [$own],
            UserModel::ROLE_TEAM_LEADER,
            UserModel::ROLE_SUPERVISOR => array_merge([$own], self::departmentNames($user)),
            default => [],
        };

        return self::$ticketScopes[$key] = array_values(array_unique(array_filter(
            array_map(static fn ($n) => strtolower(trim((string) $n)), $names),
            static fn ($n) => $n !== ''
        )));
    }

    public static function canManageTicket(?array $user, array $fields): bool
    {
        if (self::isSuperadmin($user)) {
            return true;
        }

        if ((int) ($fields['Confidential'] ?? 0) === 1) {
            return self::inConfidentialCircle($user, $fields);
        }

        $department = strtolower(resolve_base_department(ticket_department($fields)));

        return $department !== '' && in_array($department, self::ticketDepartments($user), true);
    }

    public static function canViewTicket(?array $user, array $fields): bool
    {
        if ($user === null) {
            return false;
        }

        return self::canManageTicket($user, $fields)
            || strcasecmp((string) ($fields['Requester Email'] ?? ''), (string) ($user['email'] ?? '')) === 0;
    }

    // leads of the team, the assignee and the matching sub-team staff for a confidential ticket
    public static function inConfidentialCircle(?array $user, array $fields): bool
    {
        if ($user === null || ($user['role'] ?? '') === UserModel::ROLE_REQUESTER) {
            return false;
        }

        [$department, $leaderId, $supervisorId] = self::departmentLeads($fields);
        $id = (int) ($user['id'] ?? 0);

        if ($id > 0 && ($id === $leaderId || $id === $supervisorId)) {
            return true;
        }

        $ticketId = (string) ($fields['Ticket ID'] ?? '');
        if ($ticketId !== '' && (int) ((new \App\Models\TicketMetaModel())->where('ticket_id', $ticketId)->first()['assigned_to'] ?? 0) === $id) {
            return true;
        }

        if ($department === '' || strtolower(trim((string) ($user['department'] ?? ''))) !== $department) {
            return false;
        }

        $role = trim((string) (($fields['Routing Note'] ?? '') !== '' ? ($fields['Responsible Role'] ?? '') : ($fields['Submitting Role'] ?? '')));
        if ($role === '' || empty($user['department_role_id'])) {
            return false;
        }

        $mine = \Config\Database::connect()->table('department_roles')->where('id', (int) $user['department_role_id'])->get()->getRowArray();

        return $mine !== null && strcasecmp((string) $mine['name'], $role) === 0;
    }

    private static function departmentLeads(array $fields): array
    {
        $department = strtolower(resolve_base_department(ticket_department($fields)));

        if ($department !== '') {
            foreach ((new DepartmentModel())->allOrdered() as $d) {
                if (strtolower((string) $d['name']) === $department) {
                    return [$department, (int) ($d['team_leader_id'] ?? 0), (int) ($d['supervisor_id'] ?? 0)];
                }
            }
        }

        return [$department, 0, 0];
    }

    public static function assignableStaff(array $fields): array
    {
        [$department, $leaderId, $supervisorId] = self::departmentLeads($fields);

        if ($department === '') {
            return [];
        }

        $scoped = [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR];

        if ((int) ($fields['Confidential'] ?? 0) === 1) {
            return array_values(array_filter(
                (new UserModel())->whereIn('role', $scoped)->where('is_active', 1)->orderBy('name', 'ASC')->findAll(),
                static fn ($u) => self::inConfidentialCircle($u, $fields)
            ));
        }

        return array_values(array_filter(
            (new UserModel())->whereIn('role', $scoped)->where('is_active', 1)->orderBy('name', 'ASC')->findAll(),
            static fn ($u) => strtolower(trim((string) $u['department'])) === $department
                || ($leaderId > 0 && (int) $u['id'] === $leaderId)
                || ($supervisorId > 0 && (int) $u['id'] === $supervisorId)
        ));
    }

    // team leaders and supervisors who can approve answers for the ticket's department
    public static function knowledgeReviewers(array $fields): array
    {
        [$department, $leaderId, $supervisorId] = self::departmentLeads($fields);

        if ($department === '') {
            return [];
        }

        return array_values(array_filter(
            (new UserModel())->whereIn('role', [UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR])->where('is_active', 1)->findAll(),
            static fn ($u) => ($u['role'] === UserModel::ROLE_TEAM_LEADER
                    && (($leaderId > 0 && (int) $u['id'] === $leaderId) || strtolower(trim((string) $u['department'])) === $department))
                || ($u['role'] === UserModel::ROLE_SUPERVISOR && $supervisorId > 0 && (int) $u['id'] === $supervisorId)
        ));
    }

    public static function ticketAudience(array $fields): array
    {
        [$department, $leaderId, $supervisorId] = self::departmentLeads($fields);

        $requester = strtolower(trim((string) ($fields['Requester Email'] ?? '')));

        $scoped = [UserModel::ROLE_AGENT, UserModel::ROLE_TEAM_LEADER, UserModel::ROLE_SUPERVISOR];

        if ((int) ($fields['Confidential'] ?? 0) === 1) {
            return array_values(array_filter(
                (new UserModel())->where('is_active', 1)->orderBy('name', 'ASC')->findAll(),
                static fn ($u) => $u['role'] === UserModel::ROLE_SUPERADMIN
                    || ($requester !== '' && strtolower((string) $u['email']) === $requester)
                    || self::inConfidentialCircle($u, $fields)
            ));
        }

        return array_values(array_filter(
            (new UserModel())->where('is_active', 1)->orderBy('name', 'ASC')->findAll(),
            static fn ($u) => $u['role'] === UserModel::ROLE_SUPERADMIN
                || ($requester !== '' && strtolower((string) $u['email']) === $requester)
                || ($department !== ''
                    && in_array($u['role'], $scoped, true)
                    && strtolower(trim((string) $u['department'])) === $department)
                || ($u['role'] === UserModel::ROLE_TEAM_LEADER && $leaderId > 0 && (int) $u['id'] === $leaderId)
                || ($u['role'] === UserModel::ROLE_SUPERVISOR && $supervisorId > 0 && (int) $u['id'] === $supervisorId)
        ));
    }
}
