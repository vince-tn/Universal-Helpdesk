<?php

namespace App\Libraries;

use App\Models\TicketMessageModel;
use App\Models\TicketModel;
use App\Models\UserModel;
use Config\Access;

// turns unread intake mailbox messages into tickets or ticket replies
class EmailIntake
{
    private array $ownAddresses = [];

    public function __construct(private GraphMail $graph, private array $routes)
    {
        $this->ownAddresses = array_column($routes, 'mailbox');

        $from = strtolower(trim((string) config(\Config\Email::class)->fromEmail));
        if ($from !== '') {
            $this->ownAddresses[] = $from;
        }
    }

    // returns counts per outcome
    public function run(int $top = 25): array
    {
        $counts = [];

        foreach ($this->routes as $route) {
            try {
                $messages = $this->graph->unread($route['mailbox'], $top);
            } catch (\Throwable $e) {
                log_message('error', 'email intake: {m}', ['m' => $e->getMessage()]);
                $counts['error'] = ($counts['error'] ?? 0) + 1;

                continue;
            }

            foreach ($messages as $message) {
                $outcome = $this->process($route, $message);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function process(array $route, array $message): string
    {
        $messageId = (string) ($message['id'] ?? '');

        if ($messageId === '') {
            return 'skipped';
        }

        $db   = db_connect();
        $hash = hash('sha256', $messageId);

        if ($db->table('mail_intake_log')->where('message_hash', $hash)->countAllResults() > 0) {
            $this->graph->markRead($route['mailbox'], $messageId);

            return 'seen';
        }

        // the unique hash is the claim, a second runner loses the insert
        try {
            $claimed = $db->table('mail_intake_log')->insert([
                'message_hash' => $hash,
                'message_id'   => mb_substr($messageId, 0, 512),
                'mailbox'      => $route['mailbox'],
                'sender'       => mb_substr(strtolower((string) ($message['from']['emailAddress']['address'] ?? '')), 0, 190),
                'subject'      => mb_substr((string) ($message['subject'] ?? ''), 0, 255),
                'outcome'      => 'working',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $claimed = false;
        }

        if (! $claimed) {
            return 'seen';
        }

        try {
            [$outcome, $ticketId, $detail] = $this->handle($route, $message);
        } catch (\Throwable $e) {
            log_message('error', 'email intake failed on {id}: {m}', ['id' => $messageId, 'm' => $e->getMessage()]);
            [$outcome, $ticketId, $detail] = ['error', null, $e->getMessage()];
        }

        $db->table('mail_intake_log')->where('message_hash', $hash)->update([
            'outcome'    => $outcome,
            'ticket_id'  => $ticketId,
            'detail'     => $detail === '' ? null : mb_substr($detail, 0, 255),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->graph->markRead($route['mailbox'], $messageId);

        return $outcome;
    }

    private function handle(array $route, array $message): array
    {
        $from = strtolower(trim((string) ($message['from']['emailAddress']['address'] ?? '')));
        $name = trim((string) ($message['from']['emailAddress']['name'] ?? ''));

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return ['skipped', null, 'no usable sender'];
        }
        if (MailParsing::isAutomated($from, (array) ($message['internetMessageHeaders'] ?? []))) {
            return ['skipped', null, 'automatic message'];
        }
        if (in_array($from, $this->ownAddresses, true)) {
            return ['skipped', null, 'sent by the help desk itself'];
        }

        $account = (new UserModel())->findByEmail($from);

        if ($account !== null && (int) $account['is_active'] !== 1) {
            return ['skipped', null, 'account is disabled'];
        }

        $sender = $account !== null
            ? ['id' => (int) $account['id'], 'name' => (string) $account['name'], 'email' => (string) $account['email'], 'role' => (string) $account['role'], 'department' => $account['department']]
            : ['id' => null, 'name' => mb_substr($name !== '' ? $name : $from, 0, 150), 'email' => $from, 'role' => UserModel::ROLE_REQUESTER, 'department' => null];

        $subject = (string) ($message['subject'] ?? '');
        $isHtml  = strtolower((string) ($message['body']['contentType'] ?? 'text')) === 'html';
        $body    = MailParsing::bodyText((string) ($message['body']['content'] ?? ''), $isHtml);

        $ticketId = MailParsing::ticketId($subject);

        if ($ticketId !== null) {
            $ticket = (new TicketModel())->getTicket((string) $ticketId);

            if ($ticket !== null && Org::canViewTicket($sender, $ticket['fields'])) {
                return $this->reply((string) $ticketId, $ticket['fields'], $sender, $body);
            }
        }

        return $this->create($route, $sender, $subject, $body);
    }

    private function reply(string $ticketId, array $fields, array $sender, string $body): array
    {
        if ($body === '') {
            return ['skipped', (int) $ticketId, 'empty reply'];
        }

        $saved = (new TicketMessageModel())->post(
            $ticketId,
            $sender['id'],
            $sender['name'],
            $sender['role'],
            $body,
            TicketMessageModel::KIND_MESSAGE
        );

        if ($saved === false) {
            return ['error', (int) $ticketId, 'reply could not be saved'];
        }

        (new TicketIntake())->afterReply($ticketId, $fields, $sender, $body, false);

        return ['replied', (int) $ticketId, ''];
    }

    private function create(array $route, array $sender, string $subject, string $body): array
    {
        $access = config(Access::class);

        if (! SignupPolicy::matches($sender['email'], $access->allowedDomains, $access->allowedEmails)) {
            log_message('notice', 'email intake skipped {from}: not in ALLOWED_EMAIL_DOMAINS or ALLOWED_EMAILS', ['from' => $sender['email']]);

            return ['skipped', null, 'sender not allowed'];
        }

        if (! in_array($route['department'], departments(), true)) {
            log_message('error', 'email intake: {mb} routes to unknown department {d}', ['mb' => $route['mailbox'], 'd' => $route['department']]);

            return ['skipped', null, 'mailbox department not found'];
        }

        $title = MailParsing::cleanSubject($subject);
        $text  = trim(($title !== '' ? $title . "\n\n" : '') . $body);
        $text  = mb_strlen($text) > MailParsing::MAX_BODY ? rtrim(mb_substr($text, 0, MailParsing::MAX_BODY)) : $text;

        if (mb_strlen($text) < 3) {
            return ['skipped', null, 'empty message'];
        }

        $tickets = new TicketModel();

        $id = $tickets->createFromClassification([
            'Requester Name'        => $sender['name'],
            'Requester Email'       => $sender['email'],
            'Submitting Department' => $route['department'],
            'Submitting Role'       => $route['role'],
            'Request'               => $text,
            'Request HTML'          => '',
        ], $title === '' ? [] : ['request_title' => mb_substr($title, 0, 200)], 'Local');

        if ($id === null) {
            return ['error', null, 'ticket insert failed'];
        }

        (new TicketIntake())->afterCreate($id, [
            'department'     => $route['department'],
            'role'           => $route['role'],
            'own_department' => (string) ($sender['department'] ?? ''),
            'actor'          => $sender,
        ]);

        return ['created', (int) $id, ''];
    }
}
