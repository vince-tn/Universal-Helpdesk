<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketModel extends Model
{
    protected $table         = 'tickets';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $allowedFields = [
        'requester', 'requester_email', 'submitting_department', 'request_body', 'request_html',
        'request_title', 'category', 'responsible_department', 'description',
        'expected_deliverable', 'suggested_tat', 'requirements_needed',
        'closure_criteria', 'ai_source', 'matched_catalogue_key',
        'needs_human_review', 'status', 'ai_updated_at', 'ai_resolution_note',
    ];

    public const STATUSES = ['New', 'In Progress', 'Resolved', 'Closed'];

    private const ASSISTANT_WRITABLE = ['status', 'description', 'ai_resolution_note', 'ai_updated_at'];

    private const FIELD_MAP = [
        'requester'              => 'Requester',
        'requester_email'        => 'Requester Email',
        'submitting_department'  => 'Submitting Department',
        'request_body'           => 'Request',
        'request_html'           => 'Request HTML',
        'request_title'          => 'Request Title',
        'category'               => 'Category',
        'responsible_department' => 'Responsible Department',
        'description'            => 'Description',
        'expected_deliverable'   => 'Expected Deliverable',
        'suggested_tat'          => 'Suggested TAT',
        'requirements_needed'    => 'Requirements Needed',
        'closure_criteria'       => 'Closure Criteria',
        'ai_source'              => 'AI Source',
        'status'                 => 'Status',
        'created_at'             => 'CreatedAt',
        'ai_updated_at'          => 'AI Updated At',
        'ai_resolution_note'     => 'AI Resolution Note',
        'needs_human_review'     => 'Needs Human Review',
    ];

    private const CLASSIFIER_MAP = [
        'request_title'          => 'request_title',
        'category'               => 'category',
        'responsible_department' => 'responsible_department',
        'description'            => 'description',
        'expected_deliverable'   => 'expected_deliverable',
        'suggested_tat'          => 'suggested_tat',
        'requirements_needed'    => 'requirements_needed',
        'closure_criteria'       => 'closure_criteria',
        'matched_catalogue_key'  => 'matched_catalogue_key',
    ];

    private function envelope(array $row): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $column => $key) {
            $fields[$key] = $row[$column] ?? null;
        }

        $fields['Title']  = $row['request_title'] ?? null;
        $fields['Status'] = $row['status'] ?? 'New';

        return ['id' => (string) $row['id'], 'fields' => $fields];
    }

    private const SETTLED = ['Resolved', 'Closed'];

    public function similarResolved(string $text, ?string $excludeId = null, int $limit = 3): array
    {
        $text = trim($text);

        if (mb_strlen($text) < 8) {
            return [];
        }

        $rows = $this->searchSettled($text, $excludeId, $limit);

        if ($rows === []) {
            return [];
        }

        $answers = $this->staffAnswers(array_map(static fn ($r) => (string) $r['id'], $rows));

        $out = [];

        foreach ($rows as $row) {
            $id         = (string) $row['id'];
            $resolution = trim((string) ($row['ai_resolution_note'] ?? '')) ?: trim($answers[$id] ?? '');

            if ($resolution === '') {
                continue;
            }

            $out[] = [
                'id'         => $id,
                'title'      => (string) ($row['request_title'] ?? ''),
                'request'    => mb_substr(trim((string) ($row['request_body'] ?? $row['description'] ?? '')), 0, 400),
                'resolution' => mb_substr($resolution, 0, 600),
            ];
        }

        return $out;
    }

    private function searchSettled(string $text, ?string $excludeId, int $limit): array
    {
        $builder = static fn (self $m) => $m
            ->whereIn('status', self::SETTLED)
            ->where('request_body IS NOT NULL');

        if (str_contains(strtolower($this->db->DBDriver), 'mysql')) {
            $query = $builder($this)
                ->where('MATCH(request_title, request_body, description) AGAINST (' . $this->db->escape($text) . ' IN NATURAL LANGUAGE MODE)', null, false);

            if ($excludeId !== null) {
                $query = $query->where('id !=', (int) $excludeId);
            }

            return $query->limit($limit)->find();
        }

        $words = $this->keywords($text);

        if ($words === []) {
            return [];
        }

        $query = $builder($this)->groupStart();
        foreach ($words as $word) {
            $query = $query->orLike('request_body', $word);
        }
        $query = $query->groupEnd();

        if ($excludeId !== null) {
            $query = $query->where('id !=', (int) $excludeId);
        }

        return $query->orderBy('id', 'DESC')->limit($limit)->find();
    }

    private const ANSWER_MIN_LENGTH = 25;

    private function staffAnswers(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $rows = $this->db->table('ticket_messages')
            ->select('ticket_id, body')
            ->whereIn('ticket_id', $ticketIds)
            ->whereIn('author_role', ['agent', 'superadmin'])
            ->where('is_solution', 1)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $best = [];

        foreach ($rows as $row) {
            $id   = (string) $row['ticket_id'];
            $body = trim((string) $row['body']);

            if (mb_strlen($body) < self::ANSWER_MIN_LENGTH) {
                continue;
            }

            $best[$id] = $body;
        }

        return $best;
    }

    private function keywords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($text), $m);

        return array_slice(array_unique($m[0] ?? []), 0, 6);
    }

    public function getAllTickets(): array
    {
        return array_map(
            fn ($row) => $this->envelope($row),
            $this->orderBy('created_at', 'DESC')->findAll()
        );
    }

    public function getTicket(string $id): ?array
    {
        $row = $this->find((int) $id);

        return $row === null ? null : $this->envelope($row);
    }

    public function updateStatus(string $id, string $status): bool
    {
        if (! in_array($status, self::STATUSES, true)) {
            return false;
        }

        return $this->update((int) $id, ['status' => $status]);
    }

    public function applyAssistantUpdate(string $id, array $data): bool
    {
        $clean = array_intersect_key($data, array_flip(self::ASSISTANT_WRITABLE));

        if (isset($clean['status']) && ! in_array($clean['status'], self::STATUSES, true)) {
            unset($clean['status']);
        }

        if ($clean === []) {
            return false;
        }

        return $this->update((int) $id, $clean);
    }

    public function replaceClassification(string $id, array $output, string $aiSource = 'Local'): bool
    {
        $row = ['ai_source' => in_array($aiSource, ['Local', 'Gemini'], true) ? $aiSource : 'Local'];

        foreach (self::CLASSIFIER_MAP as $key => $column) {
            $value = $output[$key] ?? null;

            if (is_string($value) || is_numeric($value)) {
                $row[$column] = (string) $value;
            }
        }

        $row['needs_human_review'] = ($output === [] || ($output['needs_human_review'] ?? 'Yes') === 'Yes') ? 1 : 0;

        if (($row['request_title'] ?? '') === '') {
            unset($row['request_title']);
        }

        return $this->update((int) $id, $row);
    }

    public function createFromClassification(array $submission, array $output, string $aiSource = 'Local'): ?string
    {
        $row = [
            'requester'             => $submission['Requester Name'] ?? '',
            'requester_email'       => $submission['Requester Email'] ?? '',
            'submitting_department' => $submission['Submitting Department'] ?? null,
            'request_body'          => $submission['Request'] ?? null,

            'request_html'          => ($submission['Request HTML'] ?? '') !== '' ? $submission['Request HTML'] : null,
            'ai_source'             => in_array($aiSource, ['Local', 'Gemini'], true) ? $aiSource : 'Local',
            'status'                => 'New',
        ];

        foreach (self::CLASSIFIER_MAP as $key => $column) {
            $value = $output[$key] ?? null;
            $row[$column] = is_string($value) || is_numeric($value) ? (string) $value : null;
        }

        $row['needs_human_review'] = ($output === [] || ($output['needs_human_review'] ?? 'Yes') === 'Yes') ? 1 : 0;

        if (($row['request_title'] ?? null) === null || trim((string) $row['request_title']) === '') {
            $row['request_title'] = 'Unclassified Request';
        }

        $id = $this->insert($row, true);

        return $id === false ? null : (string) $id;
    }
}
