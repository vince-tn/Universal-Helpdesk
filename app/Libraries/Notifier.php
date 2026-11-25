<?php

namespace App\Libraries;

use App\Models\UserModel;

class Notifier
{
    public const EXCERPT_CHARS = 300;

    private Mailer $mailer;

    public function __construct(?Mailer $mailer = null)
    {
        $this->mailer = $mailer ?? new Mailer();
    }

    public function isEnabled(): bool
    {
        $raw = strtolower(trim((string) (env('TICKET_EMAILS') ?: '')));

        return in_array($raw, ['on', '1', 'true', 'yes'], true) && $this->mailer->isConfigured();
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

    public function ticketRaised(string $ticketId, array $fields, string $request): void
    {
        $title = (string) ($fields['Request Title'] ?? $fields['Title'] ?? '');
        $link  = $this->link($ticketId);

        $this->to((string) ($fields['Requester Email'] ?? ''), self::subjectFor('raised', $ticketId, $title), implode("\n\n", [
            'Thanks - your request is logged as ticket #' . $ticketId . '.',
            'What you sent us:',
            self::excerpt($request),
            'You can follow it here: ' . $link,
            'Replying on the ticket is the fastest way to add anything.',
        ]));

        $team = array_column(Org::assignableStaff($fields), 'email');

        foreach (array_filter($team) as $address) {
            $this->to((string) $address, self::subjectFor('raised', $ticketId, $title), implode("\n\n", [
                'A new ticket has been raised with your team.',
                'From: ' . ($fields['Requester'] ?? 'Someone'),
                self::excerpt($request),
                $link,
            ]));
        }
    }

    public function messagePosted(string $ticketId, array $fields, array $author, string $body, ?array $assignee): void
    {
        $title = (string) ($fields['Request Title'] ?? $fields['Title'] ?? '');
        $link  = $this->link($ticketId);
        $staff = in_array((string) ($author['role'] ?? ''), UserModel::STAFF_ROLES, true);

        $recipients = $staff
            ? [(string) ($fields['Requester Email'] ?? '')]
            : array_column(array_filter([$assignee]) ?: Org::assignableStaff($fields), 'email');

        foreach (array_unique(array_filter($recipients)) as $address) {
            if (strcasecmp((string) $address, (string) ($author['email'] ?? '')) === 0) {
                continue;
            }

            $this->to((string) $address, self::subjectFor('replied', $ticketId, $title), implode("\n\n", [
                ($author['name'] ?? 'Someone') . ' replied on ticket #' . $ticketId . ':',
                self::excerpt($body),
                $link,
            ]));
        }
    }

    public function assigned(string $ticketId, array $fields, array $assignee): void
    {
        $title = (string) ($fields['Request Title'] ?? $fields['Title'] ?? '');

        $this->to((string) ($assignee['email'] ?? ''), self::subjectFor('assigned', $ticketId, $title), implode("\n\n", [
            'Ticket #' . $ticketId . ' has been assigned to you.',
            self::excerpt((string) ($fields['Request'] ?? $fields['Description'] ?? '')),
            $this->link($ticketId),
        ]));
    }

    public function statusChanged(string $ticketId, array $fields, string $status): void
    {
        $title = (string) ($fields['Request Title'] ?? $fields['Title'] ?? '');

        $this->to((string) ($fields['Requester Email'] ?? ''), self::subjectFor('status', $ticketId, $title), implode("\n\n", [
            'Ticket #' . $ticketId . ' is now ' . $status . '.',
            $this->link($ticketId),
            'If that is not right, reply on the ticket and it will come back to the team.',
        ]));
    }

    private function link(string $ticketId): string
    {
        try {
            return rtrim(base_url('tickets/' . $ticketId), '/');
        } catch (\Throwable $e) {
            return 'tickets/' . $ticketId;
        }
    }

    private function to(string $address, string $subject, string $body): void
    {
        $address = trim($address);

        if ($address === '' || ! $this->isEnabled()) {
            return;
        }

        try {
            $this->mailer->send($address, $subject, $body);
        } catch (\Throwable $e) {
            log_message('error', 'Ticket email to {to} failed: {message}', [
                'to'      => $address,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
