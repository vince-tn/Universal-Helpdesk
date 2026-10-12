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

    private const HISTORY_LIMIT = 12;

    private const HISTORY_BODY_CHARS = 600;

    private const REQUEST_CHARS = 1500;

    private const DESCRIPTION_MIN = 20;
    private const DESCRIPTION_MAX = 2000;

    private const CONFIDENCE = ['low', 'medium', 'high'];

    private const OPENING_OFFLINE = "Thanks - your ticket is saved.\n\n"
        . 'I could not reach the assistant to look at it just now, so nothing has been classified yet '
        . 'and a person will review it. Reply here if you want to add anything.';

    protected N8n $config;
    protected N8nService $n8n;
    protected TicketModel $tickets;
    protected TicketMessageModel $messages;
    protected TicketMetaModel $meta;
    protected Guardrails $guardrails;

    public function __construct()
    {
        $this->config     = new N8n();
        $this->n8n        = new N8nService();
        $this->tickets    = new TicketModel();
        $this->messages   = new TicketMessageModel();
        $this->meta       = new TicketMetaModel();
        $this->guardrails = new Guardrails();
    }

    public function isEnabled(): bool
    {
        return $this->config->assistantEnabled;
    }

    public function opening(
        string $ticketId,
        array $output,
        string $priorResolution = '',
        string $requestText = '',
        array $articles = [],
        ?array $route = null
    ): ?array {
        if (! $this->isEnabled()) {
            return null;
        }

        if ($output === [] || ($output['model_ok'] ?? 'Yes') !== 'Yes') {
            return $this->say($ticketId, self::OPENING_OFFLINE, TicketMessageModel::KIND_HANDOFF);
        }

        $known      = trim($priorResolution);
        $article    = $this->citedArticle($output, $articles);
        $kind       = $this->kindFor($output, $known !== '', $article !== null);
        $department = trim((string) ($output['responsible_department'] ?? ''));
        $topic      = $this->guardrails->handoffTopic($department, $requestText);
        $notice     = $route === null ? '' : $this->routeNotice($route) . "\n\n";

        // The articles were looked up for the department the requester picked.
        // After a move to a different department they belong to the wrong team,
        // so the opening only explains the move; the new team's own articles
        // take over from the next reply.
        $movedDepartment = $route !== null && strcasecmp($route['department'], $route['from_department']) !== 0;

        if ($topic !== null) {
            log_message('info', 'Guardrail: handoff topic "{topic}" on ticket {id} - no advice given', ['topic' => $topic, 'id' => $ticketId]);
        }

        if ($topic !== null || $movedDepartment) {
            return $this->say($ticketId, $notice . $this->routedBody($output), TicketMessageModel::KIND_ACKNOWLEDGE);
        }

        if ($kind !== TicketMessageModel::KIND_SUGGESTION) {
            return $this->noFirmFix($ticketId, $notice, $output, $department, $articles);
        }

        $body = trim((string) ($output['requester_message'] ?? ''));

        if ($body === '' && $known === '' && $article === null) {
            return $this->noFirmFix($ticketId, $notice, $output, $department, $articles);
        }

        $body   = $this->withSources($body, $department, $known, $article);
        $phrase = $this->guardrails->blockedPhrase($department, $body);

        if ($phrase !== null) {
            log_message('info', 'Guardrail: blocked phrase "{phrase}" in the opening on ticket {id} - replaced', ['phrase' => $phrase, 'id' => $ticketId]);

            return $this->noFirmFix($ticketId, $notice, $output, $department, $articles);
        }

        return $this->say($ticketId, $notice . $body, $kind, $this->confidenceOf($output));
    }

    /**
     * The model did not vouch for a fix. If the search still found a guide for
     * this request, the closest one is offered as "might help" - the app picks
     * it and quotes it word for word, so nothing in it is the model's own. It
     * can be near rather than exact, which is why it is labelled softly and the
     * ticket stays with the team either way.
     */
    private function noFirmFix(string $ticketId, string $notice, array $output, string $department, array $articles): ?array
    {
        $guide = array_values($articles)[0] ?? null;

        if ($guide !== null) {
            $body = $this->guideBody($department, $guide);

            if ($this->guardrails->blockedPhrase($department, $body) === null) {
                return $this->say($ticketId, $notice . $body, TicketMessageModel::KIND_GUIDE);
            }
        }

        return $this->say($ticketId, $notice . $this->routedBody($output), TicketMessageModel::KIND_ACKNOWLEDGE);
    }

    private function guideBody(string $department, array $guide): string
    {
        $who    = trim($department) === '' ? 'the assigned team' : 'the ' . trim($department) . ' team';
        $source = ($guide['department_id'] ?? null) === null ? 'This company-wide guide' : 'This guide from ' . $who;

        return "Thanks - your ticket is logged with {$who}.\n\n"
            . $source . ' might help, "' . trim((string) $guide['title']) . "\":\n"
            . (new KnowledgeBase())->excerpt($guide) . "\n\n"
            . "If that sorts it out, reply here and say so. If not, someone from {$who} will reply here.";
    }

    private function routeNotice(array $route): string
    {
        if ($route['from_role'] === '' && strcasecmp($route['department'], $route['from_department']) === 0) {
            return 'This is handled by ' . $route['to'] . ', so I have passed your ticket straight to them.';
        }

        return 'This looks like a matter for ' . $route['to'] . ' rather than ' . $route['from'] . ', so I have moved your ticket there.';
    }

    /**
     * Advice is only ever shown alongside the words it came from: the recorded
     * fix of a resolved ticket, or the department's own article, verbatim. A
     * small model restating someone's steps can drop one or invent one; the
     * original underneath it cannot.
     */
    private function withSources(string $body, string $department, string $priorFix, ?array $article): string
    {
        $parts = trim($body) === '' ? [] : [trim($body)];

        if (trim($priorFix) !== '') {
            $parts[] = "This has come up before. What fixed it last time:\n" . trim($priorFix);
        }

        if ($article !== null) {
            $team    = trim($department) === '' ? 'the team' : 'the ' . trim($department) . ' team';
            $parts[] = 'From ' . $team . "'s own guide, \"" . trim((string) $article['title']) . "\":\n"
                . (new KnowledgeBase())->excerpt($article);
        }

        return implode("\n\n", $parts);
    }

    private function routedBody(array $output): string
    {
        $team = trim((string) ($output['responsible_department'] ?? ''));

        $who = $team === '' ? 'the assigned team' : 'the ' . $team . ' team';

        return "Thanks - your ticket is logged and routed to {$who}.\n\n"
            . 'I do not have anything verified in my notes for this one, so I am not going to guess at a fix. '
            . "Someone from {$who} will reply here.\n\n"
            . 'Add anything else that might help them in the meantime.';
    }

    private function kindFor(array $output, bool $priorFixFound, bool $articleCited): string
    {
        if (($output['model_ok'] ?? 'Yes') !== 'Yes') {
            return TicketMessageModel::KIND_HANDOFF;
        }

        $fromArticle = $articleCited
            && ($output['self_service'] ?? 'No') === 'Yes'
            && $this->confidenceOf($output) === 'high';

        $repeatOfKnownFix = $priorFixFound && trim((string) ($output['matched_prior'] ?? '')) !== '';

        return ($fromArticle || $repeatOfKnownFix)
            ? TicketMessageModel::KIND_SUGGESTION
            : TicketMessageModel::KIND_ACKNOWLEDGE;
    }

    private function citedArticle(array $output, array $articles): ?array
    {
        $number   = (int) trim((string) ($output['matched_article'] ?? ''));
        $articles = array_values($articles);

        return $number >= 1 && $number <= count($articles) ? $articles[$number - 1] : null;
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

        $prior  = $this->tickets->similarResolved(trim(($fields['Request'] ?? '') . ' ' . $message), $ticketId);
        $result = $this->n8n->chat($this->payload($ticketId, $fields, $sender, $message, $excludeMessageId, $prior));

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

        $department = (string) (($fields['Responsible Department'] ?? '') ?: ($fields['Submitting Department'] ?? ''));

        $actions = $result['actions'];
        foreach (['set_description', 'resolution_note'] as $key) {
            if ($this->guardrails->blockedPhrase($department, (string) ($actions[$key] ?? '')) !== null) {
                $actions[$key] = '';
            }
        }

        $changes  = $this->applyActions($ticketId, $fields, $sender, $actions);
        $resolved = isset($changes['status']);

        $article  = $this->citedArticle($result, $result['articles'] ?? []);
        $priorFix = trim((new PriorCases())->resolutionFor($prior, $result));
        $grounded = $article !== null || $priorFix !== '';

        $offersSteps = $result['can_self_serve'] === 'Yes'
            && $result['resolution_state'] !== 'SELF_RESOLVED'
            && ! $resolved;

        if ($offersSteps && ! $grounded) {
            log_message('info', 'Guardrail: replaced uncited advice on ticket {id}', ['id' => $ticketId]);

            $result['reply']            = $this->ungroundedReply($department);
            $result['resolution_state'] = 'NEEDS_STAFF';
            $result['can_self_serve']   = 'No';
        }

        $kind = $this->replyKind($result, $grounded);

        if ($offersSteps && $grounded) {
            $result['reply'] = $this->withSources($result['reply'], $department, $priorFix, $article);
        }

        $topic   = $this->guardrails->handoffTopic($department, $message);
        $blocked = $this->guardrails->blockedPhrase($department, $result['reply']);

        if ($topic !== null || $blocked !== null) {
            log_message('info', 'Guardrail: replaced the assistant reply on ticket {id} ({why})', [
                'id'  => $ticketId,
                'why' => $topic !== null ? 'handoff topic "' . $topic . '"' : 'blocked phrase "' . $blocked . '"',
            ]);

            $result['reply']            = $this->guardedReply($department, $resolved);
            $result['resolution_state'] = $resolved ? $result['resolution_state'] : 'NEEDS_STAFF';
            $result['can_self_serve']   = 'No';

            $kind = $this->replyKind($result, false);
        }

        $stored = $this->say(
            $ticketId,
            $result['reply'],
            $kind,
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

    private function guardedReply(string $department, bool $resolved): string
    {
        if ($resolved) {
            return 'Thanks - I have marked this ticket resolved. Reply here any time if it comes back.';
        }

        $who = trim($department) === '' ? 'the assigned team' : 'the ' . trim($department) . ' team';

        return "Thanks - this is something {$who} handles directly, so I have left it for them rather than answer it myself. "
            . 'They can see this whole thread.';
    }

    private function ungroundedReply(string $department): string
    {
        $who = trim($department) === '' ? 'the assigned team' : 'the ' . trim($department) . ' team';

        return "I do not have anything written down by {$who} that covers this, so I am not going to guess at a fix. "
            . 'They can see this whole thread and will pick it up from here.';
    }

    private function replyKind(array $result, bool $grounded): string
    {
        if ($result['resolution_state'] === 'NEEDS_STAFF') {
            return TicketMessageModel::KIND_HANDOFF;
        }

        $confident = $this->confidenceOf(['confidence' => $result['confidence']]) === 'high';

        return ($grounded && $result['can_self_serve'] === 'Yes' && $confident)
            ? TicketMessageModel::KIND_SUGGESTION
            : TicketMessageModel::KIND_MESSAGE;
    }

    private function payload(string $ticketId, array $fields, array $sender, string $message, ?int $excludeMessageId, array $prior): array
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
                'body'   => mb_substr((string) $row['body'], 0, self::HISTORY_BODY_CHARS),
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
                'description'            => mb_substr((string) ($fields['Description'] ?? ''), 0, self::REQUEST_CHARS),
                'request_body'           => mb_substr((string) ($fields['Request'] ?? ''), 0, self::REQUEST_CHARS),
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
            'prior_context'       => (new PriorCases())->context($prior),
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
