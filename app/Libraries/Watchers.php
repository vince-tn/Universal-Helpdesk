<?php

namespace App\Libraries;

use App\Models\UserModel;

// people copied on a ticket, plus the person a ticket was raised for
class Watchers
{
    public const MAX = 10;

    private static array $watching = [];

    // splits a typed list into clean unique emails and the bits that are not emails
    public static function parse(string $raw, int $max = self::MAX): array
    {
        $parts   = preg_split('/[\s,;]+/u', strtolower(trim($raw)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $emails  = [];
        $invalid = [];

        foreach ($parts as $part) {
            $part = trim($part, " <>\"'");

            if ($part === '') {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $part;
                continue;
            }
            $emails[$part] = true;
        }

        $emails = array_keys($emails);

        return [
            'emails'   => array_slice($emails, 0, $max),
            'invalid'  => array_values(array_unique($invalid)),
            'too_many' => count($emails) > $max,
        ];
    }

    // true when the user is the requester or the person the ticket is for
    public static function isRequesterSide(?array $user, array $fields): bool
    {
        $email = strtolower(trim((string) ($user['email'] ?? '')));

        if ($email === '') {
            return false;
        }

        return $email === strtolower(trim((string) ($fields['Requester Email'] ?? '')))
            || $email === strtolower(trim((string) ($fields['Requested For'] ?? '')));
    }

    // confidential tickets only take watchers from the circle and the requester side
    public static function allowed(array $user, array $fields): bool
    {
        if ((int) ($fields['Confidential'] ?? 0) !== 1) {
            return true;
        }

        return Org::isSuperadmin($user) || self::isRequesterSide($user, $fields) || Org::inConfidentialCircle($user, $fields);
    }

    public static function isWatching(?array $user, string $ticketId): bool
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0 || $ticketId === '') {
            return false;
        }

        if (! isset(self::$watching[$userId])) {
            try {
                $rows = \Config\Database::connect()->table('ticket_watchers')->select('ticket_id')->where('user_id', $userId)->get()->getResultArray();
                self::$watching[$userId] = array_fill_keys(array_map(static fn ($r) => (string) $r['ticket_id'], $rows), true);
            } catch (\Throwable $e) {
                self::$watching[$userId] = [];
            }
        }

        return isset(self::$watching[$userId][$ticketId]);
    }

    public static function forTicket(string $ticketId): array
    {
        try {
            return \Config\Database::connect()->table('ticket_watchers w')
                ->select('w.id AS watch_id, u.id, u.name, u.email, u.role, u.is_active, u.notify_email, u.department, u.department_role_id')
                ->join('users u', 'u.id = w.user_id')
                ->where('w.ticket_id', (int) $ticketId)
                ->orderBy('u.name', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // emails that get the requester notices, minus the requester
    public static function followerEmails(string $ticketId, array $fields): array
    {
        $requester = strtolower(trim((string) ($fields['Requester Email'] ?? '')));
        $out       = [];

        $for = strtolower(trim((string) ($fields['Requested For'] ?? '')));
        if ($for !== '') {
            $out[$for] = true;
        }

        foreach (self::forTicket($ticketId) as $w) {
            if ((int) $w['is_active'] === 1 && self::allowed($w, $fields)) {
                $out[strtolower((string) $w['email'])] = true;
            }
        }

        unset($out[$requester]);

        return array_keys($out);
    }

    // resolves emails to active users, returns found users and the emails not found
    public static function resolve(array $emails): array
    {
        $users   = [];
        $missing = [];
        $model   = new UserModel();

        foreach ($emails as $email) {
            $user = $model->findByEmail($email);

            if ($user === null || (int) ($user['is_active'] ?? 0) !== 1 || UserModel::isExpired($user)) {
                $missing[] = $email;
                continue;
            }
            $users[] = $user;
        }

        return [$users, $missing];
    }

    // adds users as watchers, returns names skipped by the confidential rule
    public static function add(string $ticketId, array $fields, array $users, ?array $actor = null): array
    {
        $db      = \Config\Database::connect();
        $skipped = [];
        $added   = [];

        foreach ($users as $user) {
            if (self::isRequesterSide($user, $fields)) {
                continue;
            }
            if (! self::allowed($user, $fields)) {
                $skipped[] = (string) $user['name'];
                continue;
            }

            $exists = $db->table('ticket_watchers')->where(['ticket_id' => (int) $ticketId, 'user_id' => (int) $user['id']])->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            if (self::count($ticketId) >= self::MAX) {
                $skipped[] = (string) $user['name'];
                continue;
            }

            $db->table('ticket_watchers')->insert([
                'ticket_id'  => (int) $ticketId,
                'user_id'    => (int) $user['id'],
                'added_by'   => $actor['id'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $added[] = (string) $user['name'];
            unset(self::$watching[(int) $user['id']]);
        }

        if ($added !== []) {
            Activity::ticket($ticketId, 'watchers', null, 'added ' . implode(', ', $added), $actor);
        }

        return $skipped;
    }

    public static function remove(string $ticketId, int $userId, ?array $actor = null): bool
    {
        $db   = \Config\Database::connect();
        $user = (new UserModel())->find($userId);

        $db->table('ticket_watchers')->where(['ticket_id' => (int) $ticketId, 'user_id' => $userId])->delete();
        unset(self::$watching[$userId]);

        if ($db->affectedRows() > 0) {
            Activity::ticket($ticketId, 'watchers', null, 'removed ' . (string) ($user['name'] ?? 'a watcher'), $actor);

            return true;
        }

        return false;
    }

    public static function count(string $ticketId): int
    {
        return \Config\Database::connect()->table('ticket_watchers')->where('ticket_id', (int) $ticketId)->countAllResults();
    }
}
