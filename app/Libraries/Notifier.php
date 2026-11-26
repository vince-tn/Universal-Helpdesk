<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\TicketMetaModel;
use App\Models\UserModel;

// in-app and email notices for ticket events, mail goes through a queue
class Notifier
{
    public const EXCERPT_CHARS = 300;

    // emails go out unless TICKET_EMAILS is set to off and the mailer is set up
    public function isEnabled(): bool
    {
        $raw = strtolower(trim((string) (env('TICKET_EMAILS') ?: 'on')));

        return ! in_array($raw, ['off', '0', 'false', 'no'], true) && (new Mailer())->isConfigured();
    }

    public static function subjectFor(string $event, string $ticketId, string $title): string
    {
        $title = trim($title) === '' ? 'your request' : trim($title);

        $lead = match ($event) {
            'raised'   => 'Logged',
            'replied'  => 'New reply on',
            'assigned' => 'Assigned to you',
            'status'   => 'Status changed on',
            default    => 'Update on',
        };

        return mb_substr($lead . ' ticket #' . $ticketId . ': ' . $title, 0, 160);
    }

    public static function excerpt(string $text, int $max = self::EXCERPT_CHARS): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) > $max ? rtrim(mb_substr($flat, 0, $max)) . '...' : $flat;
    }

    public function created(string $ticketId, array $fields): void
    {
        $meta     = (new TicketMetaModel())->firstOrCreate($ticketId);
        $response = empty($meta['response_due_at']) ? 'within one working day' : 'by ' . format_when($meta['response_due_at']);
        $title    = $this->title($fields);

        $this->mail(
            (string) ($fields['Requester Email'] ?? ''),
            "[#{$ticketId}] We got your request: {$title}",
            "Hi " . $this->first((string) ($fields['Requester'] ?? '')) . ",\n\n"
            . "Your ticket number is #{$ticketId}.\n"
            . "Team: " . (ticket_department($fields) ?: 'being routed') . "\n"
            . "Expected first response: {$response}\n\n"
            . "Track it or reply here: " . $this->link($ticketId) . "\n\n"
            . brand_name()
        );

        $this->staff($ticketId, $fields, 'created', 'New ticket #' . $ticketId . ': ' . $title, "A new ticket is waiting for your team.\n\n" . $title . "\n" . $this->link($ticketId));
    }

    public function assigned(string $ticketId, array $fields, int $userId): void
    {
        $user = (new UserModel())->find($userId);

        if ($user === null) {
            return;
        }

        $title = $this->title($fields);
        $this->push($user, $ticketId, "Ticket #{$ticketId} was assigned to you: {$title}", 'assigned');
        $this->mailUser($user, "[#{$ticketId}] Assigned to you: {$title}", "Ticket #{$ticketId} is now yours.\n\n{$title}\n" . $this->link($ticketId));
    }

    public function replied(string $ticketId, array $fields, array $author, bool $internal): void
    {
        $title     = $this->title($fields);
        $requester = strtolower((string) ($fields['Requester Email'] ?? ''));
        $byRequester = strtolower((string) ($author['email'] ?? '')) === $requester;

        if (! $byRequester && ! $internal) {
            $this->mail($requester, "[#{$ticketId}] New reply: {$title}", ($author['name'] ?? 'The team') . " replied to your ticket.\n\nRead and reply: " . $this->link($ticketId));
            $this->pushEmail($requester, $ticketId, ($author['name'] ?? 'The team') . " replied on #{$ticketId}", 'reply');
        }

        $assignee = (int) ((new TicketMetaModel())->firstOrCreate($ticketId)['assigned_to'] ?? 0);

        if ($assignee > 0 && $assignee !== (int) ($author['id'] ?? 0)) {
            $user = (new UserModel())->find($assignee);
            if ($user !== null) {
                $what = $internal ? 'added an internal note on' : 'replied on';
                $this->push($user, $ticketId, ($author['name'] ?? 'Someone') . " {$what} #{$ticketId}", 'reply');
                if ($byRequester && ! $internal) {
                    $this->mailUser($user, "[#{$ticketId}] Requester replied: {$title}", ($author['name'] ?? 'The requester') . " replied.\n\n" . $this->link($ticketId));
                }
            }
        }
    }

    public function statusChanged(string $ticketId, array $fields, string $from, string $to): void
    {
        $title = $this->title($fields);
        $extra = $to === 'Resolved' ? "\n\nIf it is not fixed, reply on the ticket and it will reopen." : '';

        $this->mail((string) ($fields['Requester Email'] ?? ''), "[#{$ticketId}] Status: {$to}", "Your ticket \"{$title}\" moved from {$from} to {$to}.{$extra}\n\n" . $this->link($ticketId));
        $this->pushEmail((string) ($fields['Requester Email'] ?? ''), $ticketId, "Ticket #{$ticketId} is now {$to}", 'status');
    }

    public function approvalNeeded(string $ticketId, array $fields, array $approvers): void
    {
        $title = $this->title($fields);

        foreach ($approvers as $user) {
            $this->push($user, $ticketId, "Approval needed on #{$ticketId}: {$title}", 'approval');
            $this->mailUser($user, "[#{$ticketId}] Approval needed: {$title}", "A request needs your sign-off before work starts.\n\n{$title}\nApprove or reject: " . $this->link($ticketId));
        }
    }

    public function solutionPending(string $ticketId, array $fields, array $author): void
    {
        $title = $this->title($fields);

        foreach (Org::knowledgeReviewers($fields) as $user) {
            if ((int) $user['id'] === (int) ($author['id'] ?? 0)) {
                continue;
            }
            $this->push($user, $ticketId, ($author['name'] ?? 'Someone') . " marked an answer on #{$ticketId} as the solution. It needs your review: {$title}", 'review');
        }
    }

    public function slaAtRisk(string $ticketId, array $fields, string $state): void
    {
        $title = $this->title($fields);
        $word  = $state === 'breached' ? 'has breached its SLA' : 'is close to its SLA';

        $this->staff($ticketId, $fields, 'sla', "Ticket #{$ticketId} {$word}", "Ticket #{$ticketId} {$word}.\n\n{$title}\n" . $this->link($ticketId), true);
    }

    public function survey(string $ticketId, array $fields, string $token): void
    {
        $base = $this->link($ticketId) . '/feedback?t=' . $token;
        $this->mail(
            (string) ($fields['Requester Email'] ?? ''),
            "[#{$ticketId}] How did we do?",
            "Your ticket \"" . $this->title($fields) . "\" is closed.\n\nRate the help with one click:\n"
            . "5 Great: {$base}&r=5\n4 Good: {$base}&r=4\n3 Okay: {$base}&r=3\n2 Poor: {$base}&r=2\n1 Bad: {$base}&r=1\n"
        );
    }

    // queues the monday summary, skipped when emails are off or the user opted out
    public function weeklySummary(array $user, string $subject, string $body): void
    {
        $this->mailUser($user, $subject, $body);
    }

    // sends up to $limit queued mails, returns how many went out
    public static function flush(int $limit = 20): int
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('mail_queue')->where('sent_at', null)->where('attempts <', 5)->orderBy('id', 'ASC')->limit($limit)->get()->getResultArray();
        $sent = 0;

        foreach ($rows as $row) {
            $ok = (new Mailer())->send($row['to_email'], $row['subject'], $row['body']);
            $db->table('mail_queue')->where('id', $row['id'])->update([
                'attempts'   => (int) $row['attempts'] + 1,
                'sent_at'    => $ok ? date('Y-m-d H:i:s') : null,
                'last_error' => $ok ? null : 'send failed, see app log',
            ]);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    private function staff(string $ticketId, array $fields, string $type, string $line, string $body, bool $leadsOnly = false): void
    {
        $meta     = (new TicketMetaModel())->firstOrCreate($ticketId);
        $assignee = (int) ($meta['assigned_to'] ?? 0);

        foreach (Org::ticketAudience($fields) as $user) {
            if (in_array($user['role'], [UserModel::ROLE_REQUESTER, UserModel::ROLE_SUPERADMIN], true)) {
                continue;
            }
            if ($leadsOnly && (int) $user['id'] !== $assignee && $user['role'] === UserModel::ROLE_AGENT) {
                continue;
            }
            $this->push($user, $ticketId, $line, $type);
            $this->mailUser($user, $line, $body);
        }
    }

    private function push(array $user, ?string $ticketId, string $body, string $type): void
    {
        try {
            (new NotificationModel())->push((int) $user['id'], $ticketId, brand_name(), mb_substr($body, 0, 500), $type);
        } catch (\Throwable $e) {
            log_message('error', 'notification push failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    private function pushEmail(string $email, string $ticketId, string $body, string $type): void
    {
        $user = (new UserModel())->findByEmail($email);
        if ($user !== null) {
            $this->push($user, $ticketId, $body, $type);
        }
    }

    private function mailUser(array $user, string $subject, string $body): void
    {
        if ((int) ($user['notify_email'] ?? 1) === 1 && (int) ($user['is_active'] ?? 1) === 1) {
            $this->mail((string) $user['email'], $subject, $body);
        }
    }

    private function mail(string $to, string $subject, string $body): void
    {
        $to = trim($to);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || str_ends_with($to, '.local') || ! $this->isEnabled()) {
            return;
        }

        $user = (new UserModel())->findByEmail($to);
        if ($user !== null && (int) ($user['notify_email'] ?? 1) === 0) {
            return;
        }

        try {
            \Config\Database::connect()->table('mail_queue')->insert([
                'to_email'   => $to,
                'subject'    => mb_substr($subject, 0, 255),
                'body'       => $body,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'mail queue insert failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    private function title(array $fields): string
    {
        return trim((string) ($fields['Request Title'] ?? $fields['Title'] ?? '')) ?: 'your request';
    }

    private function first(string $name): string
    {
        return explode(' ', trim($name))[0] ?: 'there';
    }

    private function link(string $ticketId): string
    {
        $base = (string) (env('app_baseURL') ?: env('APP_URL') ?: config('App')->baseURL);

        return rtrim($base, '/') . '/tickets/' . $ticketId;
    }
}
