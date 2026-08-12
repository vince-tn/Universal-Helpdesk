<?php

namespace App\Libraries;

use App\Models\N8nService;
use App\Models\TicketMessageModel;
use App\Models\TicketMetaModel;
use App\Models\TicketModel;
use Config\N8n;

class TicketAssistant
{
    public const AUTHOR = 'HelpDesk Assistant';
    public const ROLE   = 'ai';

    private const HISTORY_LIMIT = 20;

    private const DESCRIPTION_MIN = 20;
    private const DESCRIPTION_MAX = 2000;

    private const CONFIDENCE = ['low', 'medium', 'high'];

    protected N8n $config;
    protected N8nService $n8n;
    protected TicketModel $tickets;
    protected TicketMessageModel $messages;
    protected TicketMetaModel $meta;

    public function __construct()
    {
        $this->config   = new N8n();
        $this->n8n      = new N8nService();
        $this->tickets  = new TicketModel();
        $this->messages = new TicketMessageModel();
        $this->meta     = new TicketMetaModel();
    }

    public function isEnabled(): bool
    {
        return $this->config->assistantEnabled;
    }

    public function opening(string $ticketId, array $output, string $priorResolution = ''): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $body = trim((string) ($output['requester_message'] ?? ''));

        $known = trim($priorResolution);

        if ($known !== '' && $body !== '') {
            $body .= "\n\nThis has come up before. What fixed it last time:\n" . $known;
        } elseif ($known !== '') {
            $body = "Thanks - I have logged this and routed it.\n\n"
                . "This has come up before. What fixed it last time:\n" . $known;
        }

        if ($body === '') {

            return $this->say(
                $ticketId,
                "Thanks - your ticket is saved.\n\n"
                . 'I could not reach the assistant to look at it just now, so nothing has been '
                . 'classified yet and a person will review it. Reply here if you want to add anything.',
                TicketMessageModel::KIND_HANDOFF
            );
        }

        return $this->say($ticketId, $body, $this->kindFor($output), $this->confidenceOf($output));
    }

    private function kindFor(array $output): string
    {
        if (($output['model_ok'] ?? 'Yes') !== 'Yes') {
            return TicketMessageModel::KIND_HANDOFF;
        }

        if (($output['needs_human_review'] ?? 'No') === 'Yes') {
            return TicketMessageModel::KIND_ACKNOWLEDGE;
        }

        $trivial = ($output['self_service'] ?? 'No') === 'Yes'
            && $this->confidenceOf($output) === 'high';

        $repeatOfKnownFix = trim((string) ($output['matched_prior'] ?? '')) !== '';

        return ($trivial || $repeatOfKnownFix)
            ? TicketMessageModel::KIND_SUGGESTION
            : TicketMessageModel::KIND_ACKNOWLEDGE;
    }

    private function confidenceOf(array $output): string
    {
        $raw = strtolower(trim((string) ($output['confidence'] ?? '')));

        return in_array($raw, self::CONFIDENCE, true) ? $raw : 'medium';
    }

    public function respond(string $ticketId, array $fields, array $sender, string $message, ?int $excludeMessageId = null): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (($fields['Status'] ?? 'New') === 'Closed') {
            return null;
        }

        $result = $this->n8n->chat($this->payload($ticketId, $fields, $sender, $message, $excludeMessageId));

        if ($result === null) {
            $offline = 'I could not reach the assistant just now, so I have not read your message yet - '
                . 'but it is saved on this ticket and the team can see it. '
                . 'If it is urgent, contact them directly rather than waiting on me.';

            $stored = $this->say($ticketId, $offline, TicketMessageModel::KIND_HANDOFF);

            return $stored === null ? null : [
                'message'   => $stored,
                'state'     => 'NEEDS_STAFF',
                'changes'   => [],
                'ai_source' => 'None',
            ];
        }

        $changes = $this->applyActions($ticketId, $fields, $sender, $result['actions']);

        $stored = $this->say(
            $ticketId,
            $result['reply'],
            $this->replyKind($result),
            $this->confidenceOf(['confidence' => $result['confidence']])
        );

        if ($stored === null) {
            return null;
        }

        return [
            'message'   => $stored,
            'state'     => $result['resolution_state'],
            'changes'   => $changes,
            'ai_source' => $result['ai_source'],
        ];
    }

    private function replyKind(array $result): string
    {
        if ($result['resolution_state'] === 'NEEDS_STAFF') {
            return TicketMessageModel::KIND_HANDOFF;
        }

        $confident = $this->confidenceOf(['confidence' => $result['confidence']]) === 'high';

        return ($result['can_self_serve'] === 'Yes' && $confident)
            ? TicketMessageModel::KIND_SUGGESTION
            : TicketMessageModel::KIND_MESSAGE;
    }

    private function payload(string $ticketId, array $fields, array $sender, string $message, ?int $excludeMessageId): array
    {
        $meta = $this->meta->firstOrCreate($ticketId);

        $history = [];
        foreach ($this->messages->forTicket($ticketId) as $row) {
            if ($excludeMessageId !== null && (int) $row['id'] === $excludeMessageId) {
                continue;
            }

            $history[] = [
                'author' => $row['author_name'],
                'role'   => $row['author_role'],
                'body'   => $row['body'],
                'at'     => $row['created_at'],
            ];
        }

        return [
            'ticket_id' => $ticketId,
            'ticket'    => [
                'title'                  => $fields['Request Title'] ?? $fields['Title'] ?? '',
                'status'                 => $fields['Status'] ?? 'New',
                'category'               => $fields['Category'] ?? '',
                'responsible_department' => $fields['Responsible Department'] ?? '',
                'submitting_department'  => $fields['Submitting Department'] ?? '',
                'requester'              => $fields['Requester'] ?? '',
                'description'            => $fields['Description'] ?? '',
                'request_body'           => $fields['Request'] ?? '',
                'expected_deliverable'   => $fields['Expected Deliverable'] ?? '',
                'requirements_needed'    => $fields['Requirements Needed'] ?? '',
                'closure_criteria'       => $fields['Closure Criteria'] ?? '',
                'suggested_tat'          => $fields['Suggested TAT'] ?? '',
                'priority'               => $meta['priority'] ?? 'Medium',
            ],
            'sender_name'         => $sender['name'] ?? '',
            'sender_role'         => $sender['role'] ?? 'requester',
            'sender_is_requester' => $this->isRequester($fields, $sender) ? 'Yes' : 'No',
            'message'             => $message,
            'history'             => array_slice($history, -self::HISTORY_LIMIT),
        ];
    }

    public function isRequester(array $fields, array $user): bool
    {
        return strcasecmp((string) ($fields['Requester Email'] ?? ''), (string) ($user['email'] ?? '')) === 0;
    }

    private function applyActions(string $ticketId, array $fields, array $sender, array $actions): array
    {
        $status  = (string) ($fields['Status'] ?? 'New');
        $update  = [];
        $changes = [];

        $wantsResolved = (string) ($actions['set_status'] ?? '') === 'Resolved';

        if ($wantsResolved
            && $this->isRequester($fields, $sender)
            && ! in_array($status, ['Resolved', 'Closed'], true)
        ) {
            $update['status']  = 'Resolved';
            $changes['status'] = 'Resolved';
        }

        $description = trim((string) ($actions['set_description'] ?? ''));

        if ($description !== ''
            && mb_strlen($description) >= self::DESCRIPTION_MIN
            && $description !== trim((string) ($fields['Description'] ?? ''))
        ) {
            $update['description']  = mb_substr($description, 0, self::DESCRIPTION_MAX);
            $changes['description'] = $update['description'];
        }

        $note = trim((string) ($actions['resolution_note'] ?? ''));

        if ($note !== '' && isset($update['status'])) {
            $update['ai_resolution_note']  = mb_substr($note, 0, 500);
            $changes['resolution_note'] = $update['ai_resolution_note'];
        }

        if ($update === []) {
            return [];
        }

        $update['ai_updated_at'] = date('Y-m-d H:i:s');

        if (! $this->tickets->applyAssistantUpdate($ticketId, $update)) {
            log_message('error', 'Assistant update failed on ticket {id}', ['id' => $ticketId]);

            return [];
        }

        log_message('info', 'Assistant updated ticket {id}: {fields}', [
            'id'     => $ticketId,
            'fields' => implode(', ', array_keys($changes)),
        ]);

        return $changes;
    }

    private function say(
        string $ticketId,
        string $body,
        string $kind = TicketMessageModel::KIND_MESSAGE,
        ?string $confidence = null
    ): ?array {
        $id = $this->messages->post($ticketId, null, self::AUTHOR, self::ROLE, $body, $kind, $confidence);

        if ($id === false) {
            log_message('error', 'Could not store the assistant message on ticket {id}', ['id' => $ticketId]);

            return null;
        }

        return [
            'id'            => (int) $id,
            'ticket_id'     => $ticketId,
            'user_id'       => null,
            'author_name'   => self::AUTHOR,
            'author_role'   => self::ROLE,
            'kind'          => $kind,
            'ai_confidence' => $confidence,
            'body'          => $body,
            'created_at'    => date('Y-m-d H:i:s'),
        ];
    }
}
