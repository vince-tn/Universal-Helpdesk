<?php

namespace App\Controllers;

use App\Libraries\Activity;
use App\Libraries\AiDeflection;
use App\Libraries\Approvals;
use App\Libraries\BulkTickets;
use App\Libraries\ExportGuard;
use App\Libraries\Html;
use App\Libraries\KnowledgeReview;
use App\Libraries\Mentions;
use App\Libraries\Notifier;
use App\Libraries\Sla;
use App\Libraries\Org;
use App\Libraries\PriorCases;
use App\Libraries\RoutingDirectory;
use App\Libraries\ScreenshotReader;
use App\Libraries\TicketAssistant;
use App\Libraries\TicketIntake;
use App\Libraries\AutoAssign;
use App\Libraries\Duplicates;
use App\Libraries\SignupPolicy;
use App\Libraries\Watchers;
use App\Models\AiFeedbackModel;
use App\Models\N8nService;
use App\Models\TicketModel;
use App\Models\TicketMetaModel;
use App\Models\TicketMessageModel;
use App\Models\UserModel;

class TicketController extends BaseController
{
    protected N8nService $n8nService;
    protected TicketModel $ticketModel;
    protected TicketMetaModel $metaModel;
    protected TicketMessageModel $messageModel;
    protected UserModel $userModel;
    protected TicketAssistant $assistant;
    protected PriorCases $priorCases;

    public function __construct()
    {
        $this->n8nService   = new N8nService();
        $this->ticketModel  = new TicketModel();
        $this->metaModel    = new TicketMetaModel();
        $this->messageModel = new TicketMessageModel();
        $this->userModel    = new UserModel();
        $this->assistant    = new TicketAssistant();
        $this->priorCases   = new PriorCases();
    }

    private function canManage(array $fields, array $user): bool
    {
        return Org::canManageTicket($user, $fields);
    }

    private function canAccess(array $fields, array $user): bool
    {
        return Org::canViewTicket($user, $fields);
    }

    private function scopedTickets(array $tickets, array $user): array
    {
        return array_values(array_filter($tickets, fn ($t) => $this->canAccess($t['fields'] ?? [], $user)));
    }

    private function enrichedScopedTickets(array $user): array
    {
        $tickets = $this->scopedTickets($this->ticketModel->getAllTickets(), $user);

        $ticketIds = array_map(fn ($t) => (string) ($t['id'] ?? ''), $tickets);
        $metaById  = $this->metaModel->forTickets($ticketIds);

        $assignedIds = array_filter(array_column($metaById, 'assigned_to'));
        $agentNames  = [];
        if (! empty($assignedIds)) {
            foreach ($this->userModel->whereIn('id', array_unique($assignedIds))->findAll() as $agent) {
                $agentNames[$agent['id']] = $agent['name'];
            }
        }

        foreach ($tickets as &$t) {
            $id   = (string) ($t['id'] ?? '');
            $meta = $metaById[$id] ?? ['priority' => 'Medium', 'due_date' => null, 'assigned_to' => null];
            $sla  = new Sla();

            $t['department'] = ticket_department($t['fields'] ?? []);
            $t['meta'] = [
                'priority'      => $meta['priority'] ?? 'Medium',
                'due_date'      => $meta['due_date'] ?? null,
                'assigned_to'   => $meta['assigned_to'] ?? null,
                'assigned_name' => isset($meta['assigned_to']) ? ($agentNames[$meta['assigned_to']] ?? null) : null,
                'due_at'        => $meta['due_at'] ?? null,
                'sla'           => $sla->state($meta, (string) ($t['fields']['Status'] ?? 'New'), $t['fields']['CreatedAt'] ?? null),
            ];
        }
        unset($t);

        return $tickets;
    }

    private function applyFilters(array $tickets, array $user, array $filters): array
    {
        [$q, $status, $category, $priority, $department] = [
            $filters['q'], $filters['status'], $filters['category'], $filters['priority'], $filters['department'],
        ];

        if ($q !== '') {
            $needle  = mb_strtolower($q);
            $tickets = array_values(array_filter($tickets, function ($t) use ($needle) {
                $f = $t['fields'] ?? [];
                $haystack = mb_strtolower(($f['Request Title'] ?? $f['Title'] ?? '') . ' ' . ($f['Requester'] ?? '') . ' ' . ($f['Requester Email'] ?? ''));
                return str_contains($haystack, $needle);
            }));
        }
        if ($status !== '') {
            $tickets = array_values(array_filter($tickets, fn ($t) => ($t['fields']['Status'] ?? 'New') === $status));
        }
        if ($category !== '') {
            $tickets = array_values(array_filter($tickets, fn ($t) => ($t['fields']['Category'] ?? '') === $category));
        }
        if ($priority !== '') {
            $tickets = array_values(array_filter($tickets, fn ($t) => ($t['meta']['priority'] ?? 'Medium') === $priority));
        }
        $view = $filters['view'] ?? '';
        if ($view !== '') {
            $tickets = array_values(array_filter($tickets, function ($t) use ($view, $user) {
                $f    = $t['fields'] ?? [];
                $open = in_array($f['Status'] ?? 'New', TicketModel::OPEN_STATUSES, true);

                return match ($view) {
                    'mine'       => Watchers::isRequesterSide($user, $f),
                    'assigned'   => (int) ($t['meta']['assigned_to'] ?? 0) === (int) $user['id'] && $open,
                    'team'       => $open && in_array(strtolower(resolve_base_department($t['department'] ?? '')), Org::ticketDepartments($user), true),
                    'unassigned' => $open && empty($t['meta']['assigned_to']),
                    'breaching'  => $open && in_array($t['meta']['sla'] ?? '', ['at_risk', 'breached'], true),
                    'approval'   => ($f['Status'] ?? '') === 'Pending Approval',
                    default      => true,
                };
            }));
        }

        if ($department !== '' && $user['role'] === UserModel::ROLE_SUPERADMIN) {
            $tickets = array_values(array_filter($tickets, fn ($t) => strcasecmp(resolve_base_department($t['department'] ?? ''), $department) === 0));
        }

        return $tickets;
    }

    private function requestFilters(): array
    {
        return [
            'q'          => trim((string) ($this->request->getGet('q') ?? '')),
            'status'     => (string) ($this->request->getGet('status') ?? ''),
            'category'   => (string) ($this->request->getGet('category') ?? ''),
            'priority'   => (string) ($this->request->getGet('priority') ?? ''),
            'department' => (string) ($this->request->getGet('department') ?? ''),
            'view'       => (string) ($this->request->getGet('view') ?? ''),
        ];
    }

    public function index()
    {
        $user      = current_user();
        $allScoped = $this->enrichedScopedTickets($user);
        $filters   = $this->requestFilters();
        $tickets   = $this->applyFilters($allScoped, $user, $filters);

        $sort = (string) ($this->request->getGet('sort') ?? 'created_desc');
        $sorters = [
            'created_desc' => fn ($a, $b) => strtotime($b['fields']['CreatedAt'] ?? 'now') <=> strtotime($a['fields']['CreatedAt'] ?? 'now'),
            'created_asc'  => fn ($a, $b) => strtotime($a['fields']['CreatedAt'] ?? 'now') <=> strtotime($b['fields']['CreatedAt'] ?? 'now'),
            'title_asc'    => fn ($a, $b) => strcasecmp($a['fields']['Request Title'] ?? $a['fields']['Title'] ?? '', $b['fields']['Request Title'] ?? $b['fields']['Title'] ?? ''),
            'priority_desc' => function ($a, $b) {
                $order = array_flip(priorities());
                return ($order[$b['meta']['priority'] ?? 'Medium'] ?? 0) <=> ($order[$a['meta']['priority'] ?? 'Medium'] ?? 0);
            },
        ];
        $sorters['title_desc']   = fn ($a, $b) => $sorters['title_asc']($b, $a);
        $sorters['priority_asc'] = fn ($a, $b) => $sorters['priority_desc']($b, $a);
        usort($tickets, $sorters[$sort] ?? $sorters['created_desc']);

        $perPage     = 15;
        $page        = max(1, (int) ($this->request->getGet('page') ?? 1));
        $totalFound  = count($tickets);
        $totalPages  = max(1, (int) ceil($totalFound / $perPage));
        $page        = min($page, $totalPages);
        $pageTickets = array_slice($tickets, ($page - 1) * $perPage, $perPage);

        $canBulk   = BulkTickets::canUse($user);
        $bulkStaff = $canBulk ? $this->bulkStaff($pageTickets, $user) : [];

        $statusCounts = array_fill_keys(TicketModel::STATUSES, 0);
        foreach ($allScoped as $t) {
            $s = $t['fields']['Status'] ?? 'New';
            if (isset($statusCounts[$s])) {
                $statusCounts[$s]++;
            }
        }

        $categories = array_values(array_unique(array_filter(array_map(
            fn ($t) => $t['fields']['Category'] ?? null,
            $allScoped
        ))));
        sort($categories);

        return view('tickets/index', array_merge($filters, [
            'tickets'       => $pageTickets,
            'total'         => count($allScoped),
            'categories'    => $categories,
            'statusCounts'  => $statusCounts,
            'sort'          => $sort,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'totalFound'    => $totalFound,
            'canBulk'       => $canBulk,
            'bulkStaff'     => $bulkStaff,
        ]));
    }

    // marks which page rows the user can manage and lists who they can assign
    private function bulkStaff(array &$pageTickets, array $user): array
    {
        $staff = [];
        $seen  = [];

        foreach ($pageTickets as &$t) {
            $fields          = $t['fields'] ?? [];
            $t['can_manage'] = $this->canManage($fields, $user);

            if (! $t['can_manage']) {
                continue;
            }

            $key = (int) ($fields['Confidential'] ?? 0) === 1 ? 'c:' . ($t['id'] ?? '') : 'd:' . strtolower(resolve_base_department($t['department'] ?? ''));

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            foreach (Org::assignableStaff($fields) as $s) {
                $staff[(int) $s['id']] = (string) $s['name'];
            }
        }
        unset($t);

        asort($staff, SORT_NATURAL | SORT_FLAG_CASE);

        return $staff;
    }

    public function bulk()
    {
        $user = current_user();

        if (! BulkTickets::canUse($user)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $back = '/tickets' . $this->bulkReturnQuery();
        $ids  = array_values(array_unique(array_filter(
            array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) ($this->request->getPost('ids') ?? [])),
            static fn ($v) => preg_match('/^[A-Za-z0-9_-]{1,100}$/', $v) === 1
        )));

        if ($ids === []) {
            return redirect()->to($back)->with('error', 'Tick at least one ticket first.');
        }
        if (count($ids) > BulkTickets::MAX) {
            return redirect()->to($back)->with('error', 'Pick up to ' . BulkTickets::MAX . ' tickets at a time.');
        }

        $action = (string) $this->request->getPost('action');
        $value  = match ($action) {
            'assign'   => (string) $this->request->getPost('assign_to'),
            'status'   => (string) $this->request->getPost('status'),
            'priority' => (string) $this->request->getPost('priority'),
            default    => '',
        };
        $note = trim((string) $this->request->getPost('note'));

        $error = BulkTickets::requestError($action, $value, $note);
        if ($error !== null) {
            return redirect()->to($back)->with('error', $error);
        }

        [$kind, $target] = BulkTickets::normalise($action, $value);

        $super   = Org::isSuperadmin($user);
        $name    = $kind === 'assign' ? (string) ($this->userModel->find((int) $target)['name'] ?? '') : '';
        $updated = 0;
        $skipped = [];

        foreach ($ids as $id) {
            $ticket = $this->ticketModel->getTicket($id);

            if ($ticket === null) {
                $skipped['missing'] = ($skipped['missing'] ?? 0) + 1;

                continue;
            }

            $fields = $ticket['fields'] ?? $ticket;
            $manage = $this->canManage($fields, $user);
            $meta   = $manage ? $this->metaModel->firstOrCreate($id) : [];
            $state  = [
                'can_manage'  => $manage,
                'status'      => (string) ($fields['Status'] ?? 'New'),
                'assigned_to' => (int) ($meta['assigned_to'] ?? 0),
                'priority'    => (string) ($meta['priority'] ?? 'Medium'),
                'assignable'  => $manage && $kind === 'assign' ? array_column(Org::assignableStaff($fields), 'id') : [],
            ];

            $reason = BulkTickets::skipReason($kind, $target, $state, $super);

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            if ($kind === 'status') {
                $this->applyStatus($id, $fields, $state['status'], $target, $note);
            } elseif ($kind === 'priority') {
                $this->applyPriority($id, $fields, $state['priority'], $target);
            } else {
                $this->applyAssignee($id, $fields, (int) $target, $name);
            }

            $updated++;
        }

        $summary = BulkTickets::summary($updated, $skipped);
        Activity::audit('bulk_update', $kind . ' to ' . ($kind === 'assign' ? $name : $target) . ': ' . $summary);

        return redirect()->to($back)->with($updated > 0 ? 'success' : 'error', $summary);
    }

    // keeps the dashboard filters, sort and page after a bulk change
    private function bulkReturnQuery(): string
    {
        parse_str((string) $this->request->getPost('return'), $query);

        $keep = [];
        foreach (['q', 'status', 'category', 'priority', 'department', 'view', 'sort', 'page'] as $key) {
            $v = $query[$key] ?? '';
            if (is_scalar($v) && (string) $v !== '') {
                $keep[$key] = mb_substr((string) $v, 0, 200);
            }
        }

        return $keep === [] ? '' : '?' . http_build_query($keep);
    }

    public function export()
    {
        $user = current_user();

        if (! ExportGuard::allowed($user)) {
            return redirect()->to('/tickets')->with('error', 'Only super admins and supervisors can export ticket data.');
        }

        $tickets = $this->applyFilters($this->enrichedScopedTickets($user), $user, $this->requestFilters());

        $rows = array_map(function ($t) {
            $f = $t['fields'] ?? [];

            return [
                'ID'                  => $t['id'] ?? '',
                'Title'               => $f['Request Title'] ?? $f['Title'] ?? '',
                'Department'          => $t['department'] ?? '',
                'Category'            => $f['Category'] ?? '',
                'Requester'           => $f['Requester'] ?? '',
                'Requester Email'     => $f['Requester Email'] ?? '',
                'Status'              => $f['Status'] ?? 'New',
                'Priority'            => $t['meta']['priority'] ?? 'Medium',
                'Due Date'            => $t['meta']['due_date'] ?? '',
                'Assigned To'         => $t['meta']['assigned_name'] ?? '',
                'AI Source'           => $f['AI Source'] ?? '',
                'Suggested TAT'       => $f['Suggested TAT'] ?? '',
                'Created At'          => $f['CreatedAt'] ?? '',
            ];
        }, $tickets);

        $format   = strtolower((string) ($this->request->getGet('format') ?? 'csv'));
        $format   = in_array($format, ['json', 'xls'], true) ? $format : 'csv';
        $filename = 'tickets_export_' . date('Y-m-d_His');

        ExportGuard::log($user, 'raw ticket export, format ' . $format . ', ' . count($rows) . ' rows, filters ' . http_build_query(array_filter($this->requestFilters())));

        return match ($format) {
            'json' => $this->response
                ->setContentType('application/json')
                ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}.json\"")
                ->setBody(json_encode($rows, JSON_PRETTY_PRINT)),
            'xls' => $this->exportXls($rows, $filename),
            default => $this->exportCsv($rows, $filename),
        };
    }

    private function exportCsv(array $rows, string $filename)
    {
        $out = fopen('php://temp', 'w+');

        if (! empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $this->spreadsheetSafe($v), $row));
            }
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setContentType('text/csv')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}.csv\"")
            ->setBody($csv);
    }

    private function spreadsheetSafe($value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    private function exportXls(array $rows, string $filename)
    {
        $columns = empty($rows) ? [] : array_keys($rows[0]);

        $html = '<html><head><meta charset="UTF-8"></head><body><table border="1">';
        $html .= '<tr>' . implode('', array_map(fn ($c) => '<th>' . esc($c) . '</th>', $columns)) . '</tr>';
        foreach ($rows as $row) {
            $html .= '<tr>' . implode('', array_map(fn ($v) => '<td>' . esc($this->spreadsheetSafe($v)) . '</td>', $row)) . '</tr>';
        }
        $html .= '</table></body></html>';

        return $this->response
            ->setContentType('application/vnd.ms-excel')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}.xls\"")
            ->setBody($html);
    }

    public function create()
    {
        $active = array_filter(
            (new \App\Models\DepartmentModel())->withRoles(),
            static fn ($d) => (int) $d['is_active'] === 1
        );

        $services = \Config\Database::connect()->table('services')->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')->get()->getResultArray();
        $activeNames = array_map('strtolower', array_column($active, 'name'));
        $services    = array_values(array_filter($services, static fn ($s) => in_array(strtolower($s['department']), $activeNames, true)));

        return view('tickets/create', [
            'user'        => current_user(),
            'roles'       => array_values($active),
            'services'    => $services,
            'departments' => departments(),
            'room'        => (int) ($this->request->getGet('room') ?? 0),
        ]);
    }

    public function store()
    {
        $user = current_user();

        $html = (new Html())->clean((string) $this->request->getPost('request_html'));
        $text = (new Html())->toText($html);

        if ($text === '') {
            $text = trim((string) $this->request->getPost('request_description'));
            $html = '';
        }

        $department = (string) $this->request->getPost('submitting_department');
        $role       = trim((string) $this->request->getPost('submitting_role'));

        $service = $this->service(trim((string) $this->request->getPost('service_key')));
        $answers = [];

        if ($service !== null) {
            $department = $service['department'];
            $role       = (string) ($service['role'] ?? '');

            foreach (json_decode((string) $service['fields_json'], true) ?: [] as $field) {
                $value = trim((string) ($this->request->getPost('svc')[$field['key']] ?? ''));

                if ($value === '' && ! empty($field['required'])) {
                    return redirect()->back()->withInput()->with('error', 'Please fill in "' . $field['label'] . '".');
                }
                if ($value !== '') {
                    $answers[$field['label']] = mb_substr($value, 0, 300);
                }
            }

            if ($answers !== []) {
                $lines = ['Request type: ' . $service['name']];
                foreach ($answers as $label => $value) {
                    $lines[] = $label . ': ' . $value;
                }
                $text = implode("\n", $lines) . "\n\n" . $text;
            }
        }

        $ownDepartment = trim((string) $this->request->getPost('requester_department')) ?: trim((string) ($user['department'] ?? ''));
        $people        = (string) $this->request->getPost('people_affected');
        $stopped       = (string) $this->request->getPost('work_stopped');
        $neededBy      = trim((string) $this->request->getPost('needed_by'));

        $error = match (true) {
            ! in_array($department, departments(), true)
                => 'Choose what you need help with, or the team that should handle it.',
            mb_strlen($text) < 10
                => 'Please describe your request in a bit more detail - at least a sentence.',
            mb_strlen($text) > 5000
                => 'That request is very long. Please keep it under 5000 characters and attach the detail instead.',
            default => null,
        };

        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        [$requestedFor, $watchers, $error] = $this->requestPeople($user);

        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $submission = [
            'Requester Name'        => $user['name'],
            'Requester Email'       => $user['email'],
            'Submitting Department' => $department,
            'Submitting Role'       => $role,
            'Request'               => $text,
            'Request HTML'          => $html,
        ];

        $id = $this->ticketModel->createFromClassification($submission, [], 'Local');

        if ($id === null) {
            log_message('error', 'Ticket insert failed for {email}', ['email' => $user['email']]);

            return redirect()->back()->withInput()
                ->with('error', 'Something went wrong saving your ticket. Please try again.');
        }

        $created = (new TicketIntake())->afterCreate($id, [
            'department'     => $department,
            'role'           => $role,
            'service'        => $service,
            'answers'        => $answers,
            'own_department' => $ownDepartment,
            'people'         => $people,
            'stopped'        => $stopped,
            'needed_by'      => $neededBy,
            'requested_for'  => $requestedFor,
            'watchers'       => $watchers,
        ]);

        $done = "Ticket #{$id} submitted. We emailed you a copy.";

        if (! empty($created['_skipped_watchers'])) {
            $done .= ' Not copied, as this ticket is confidential: ' . implode(', ', $created['_skipped_watchers']) . '.';
        }

        return redirect()->to("/tickets/{$id}")->with('success', $done);
    }

    // reads request for and watchers from the form, returns email, users and an error
    private function requestPeople(array $user): array
    {
        $for = strtolower(trim((string) $this->request->getPost('requested_for')));

        if ($for === strtolower((string) $user['email'])) {
            $for = '';
        }

        if ($for !== '') {
            if (filter_var($for, FILTER_VALIDATE_EMAIL) === false || mb_strlen($for) > 190) {
                return ['', [], 'The "Request for" email is not a valid address.'];
            }

            $person = $this->userModel->findByEmail($for);

            if ($person !== null && (int) ($person['is_active'] ?? 0) !== 1) {
                return ['', [], 'The person in "Request for" no longer has an active account.'];
            }
            if ($person === null && ! (new SignupPolicy())->allows($for)) {
                return ['', [], 'The "Request for" email must belong to a colleague.'];
            }
        }

        $parsed = Watchers::parse((string) $this->request->getPost('watchers'));

        if ($parsed['invalid'] !== []) {
            return ['', [], 'These are not email addresses: ' . implode(', ', array_slice($parsed['invalid'], 0, 5)) . '.'];
        }
        if ($parsed['too_many']) {
            return ['', [], 'You can copy up to ' . Watchers::MAX . ' people.'];
        }

        [$watchers, $missing] = Watchers::resolve($parsed['emails']);

        if ($missing !== []) {
            return ['', [], 'No active account for: ' . implode(', ', array_slice($missing, 0, 5)) . '. Only colleagues with an account can be copied.'];
        }

        return [$for, $watchers, null];
    }

    public function classify(string $id)
    {
        $ticket = $this->ticketModel->getTicket($id);

        if ($ticket === null) {
            return $this->assistError('Ticket not found.', 404);
        }

        $user   = current_user();
        $fields = $ticket['fields'] ?? $ticket;

        if (! $this->canAccess($fields, $user)) {
            return $this->assistError('Ticket not found.', 404);
        }

        if (! $this->ticketModel->awaitsClassification($fields) || ! $this->ticketModel->claimForClassification($id)) {
            return $this->response->setJSON(['ok' => true, 'changed' => false, 'message' => null]);
        }

        ignore_user_abort(true);

        $session = session();
        if (method_exists($session, 'close')) {
            $session->close();
        }

        $text       = (string) ($fields['Request'] ?? '');
        $department = (string) ($fields['Submitting Department'] ?? '');
        $prior      = $this->ticketModel->similarResolved($text, $id);

        $notes = (new ScreenshotReader())->notesFor(
            $id,
            (string) ($fields['Request HTML'] ?? ''),
            (string) ($fields['Image Notes'] ?? ''),
            $text,
            $this->n8nService->cloudAllowed($department) && (int) ($fields['Confidential'] ?? 0) !== 1
        );

        $text .= ScreenshotReader::notesBlock($notes);

        $localOnly = (int) ($fields['Confidential'] ?? 0) === 1;

        $result = $this->n8nService->classify([
            'Requester Name'        => $fields['Requester'] ?? '',
            'Requester Email'       => $fields['Requester Email'] ?? '',
            'Submitting Department' => $department,
            'Submitting Role'       => $fields['Submitting Role'] ?? '',
            'Request'               => $text,
        ], $this->priorCases->context($prior), $localOnly);

        if ($result === null) {
            $stored = $this->messageModel->hasAssistantMessage($id)
                ? null
                : $this->assistant->opening($id, [], '');

            return $this->response->setJSON([
                'ok'      => true,
                'changed' => false,
                'message' => $stored === null ? null : $this->renderMessage($stored, $fields),
            ]);
        }

        $output = $result['output'] ?? [];

        $this->ticketModel->replaceClassification($id, $output, $result['ai_source'] ?? 'Local');

        $meta = $this->metaModel->firstOrCreate($id);

        if (empty($meta['people_affected'])) {
            $this->metaModel->updateForTicket($id, TicketMetaModel::fromClassification($output));
        }

        $service = $this->service((string) ($fields['Service Key'] ?? ''));
        if ($service !== null) {
            $this->ticketModel->update((int) $id, ['category' => $service['category']]);
        }

        $fresh = $this->ticketModel->getTicket($id)['fields'] ?? [];
        $meta  = $this->metaModel->firstOrCreate($id);
        (new Sla())->stamp($id, (string) ($fresh['CreatedAt'] ?? date('Y-m-d H:i:s')), (string) ($fresh['Category'] ?? 'Common Request'), (string) ($meta['priority'] ?? 'Medium'));

        $route = (new RoutingDirectory())->reroute($id, $result['routing'] ?? [], $output, $text);

        if ($route !== null) {
            $output['responsible_department'] = $route['department'];
            $this->ticketModel->update((int) $id, ['is_confidential' => $this->isConfidential($route['department'], $route['role']) ? 1 : 0]);
            Activity::ticket($id, 'routed', $route['reason'] ?? '', $route['to'], ['id' => null, 'name' => 'Assistant']);
            (new AutoAssign())->afterReroute($id, $this->ticketModel->getTicket($id)['fields'] ?? []);
        }

        $stored = $this->assistant->opening(
            $id,
            $output,
            $this->priorCases->resolutionFor($prior, $output),
            $text,
            $result['articles'] ?? [],
            $route
        );

        return $this->response->setJSON([
            'ok'      => true,
            'changed' => true,
            'message' => $stored === null ? null : $this->renderMessage($stored, $fields),
        ]);
    }

    public function show(string $id)
    {
        $ticket = $this->ticketModel->getTicket($id);

        if ($ticket === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $user   = current_user();
        $fields = $ticket['fields'] ?? $ticket;

        if (! $this->canAccess($fields, $user)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $meta = $this->metaModel->firstOrCreate($id);

        $assignedTo = null;
        if (! empty($meta['assigned_to'])) {
            $assignedTo = $this->userModel->find($meta['assigned_to']);
        }

        return view('tickets/show', [
            'ticket'     => $ticket,
            'meta'       => $meta,
            'staff'      => Org::assignableStaff($fields),
            'assignedTo' => $assignedTo,
            'messages'   => $this->withVotes($this->messageModel->forTicket($id, $this->canManage($fields, $user)), $fields, $user),
            'canManage'  => $this->canManage($fields, $user),
            'events'     => Activity::forTicket($id),
            'approvals'  => (new Approvals())->forTicket($id),
            'canApprove' => ($step = (new Approvals())->current($id)) !== null && (new Approvals())->canDecide($user, $fields, $step),
            'slaState'   => (new Sla())->state($meta, (string) ($fields['Status'] ?? 'New'), $fields['CreatedAt'] ?? null),
            'canned'     => $this->canManage($fields, $user) ? $this->canned($fields) : [],
            'feedback'   => \Config\Database::connect()->table('ticket_feedback')->where('ticket_id', $id)->get()->getRowArray(),
            'awaitingAi' => $this->ticketModel->awaitsClassification($fields),
            'teams'      => $this->canManage($fields, $user) ? (new RoutingDirectory())->entries() : [],
            'canReply'   => Org::canReplyTicket($user, $fields),
            'watchers'   => Watchers::forTicket($id),
            'requestedFor' => ($fields['Requested For'] ?? '') !== '' ? $this->userModel->findByEmail((string) $fields['Requested For']) : null,
            'links'      => $this->related(Duplicates::links($id), $user),
            'duplicate'  => $this->canManage($fields, $user) ? $this->duplicateOf($fields, $user) : null,
        ]);
    }

    // linked tickets the viewer may open, with title and status
    private function related(array $ids, array $user): array
    {
        $out = [];

        foreach ($ids as $linked) {
            $t = $this->ticketModel->getTicket((string) $linked);

            if ($t !== null && $this->canAccess($t['fields'], $user)) {
                $out[] = ['id' => (string) $linked, 'title' => (string) ($t['fields']['Request Title'] ?? ''), 'status' => (string) ($t['fields']['Status'] ?? 'New')];
            }
        }

        return $out;
    }

    // the open ticket this one probably repeats, if staff can manage it too
    private function duplicateOf(array $fields, array $user): ?array
    {
        $target = (int) ($fields['Duplicate Of'] ?? 0);

        if ($target <= 0 || ! in_array((string) ($fields['Status'] ?? 'New'), TicketModel::OPEN_STATUSES, true)) {
            return null;
        }

        $t = $this->ticketModel->getTicket((string) $target);

        if ($t === null || ! in_array((string) ($t['fields']['Status'] ?? 'New'), TicketModel::OPEN_STATUSES, true)) {
            return null;
        }

        return [
            'id'        => (string) $target,
            'title'     => (string) ($t['fields']['Request Title'] ?? ''),
            'canMerge'  => $this->canManage($t['fields'], $user),
        ];
    }

    // merge this ticket into another open one
    public function merge(string $id)
    {
        $fields   = $this->requireManageAccess($id);
        $targetId = (string) (int) ltrim(trim((string) $this->request->getPost('target_id')), '#');
        $target   = $targetId === '0' ? null : $this->ticketModel->getTicket($targetId);
        $user     = current_user();

        $error = match (true) {
            $target === null || $targetId === $id         => 'Enter the number of another ticket.',
            ! $this->canManage($target['fields'], $user)    => 'You can only merge into a ticket you manage.',
            ! in_array((string) ($fields['Status'] ?? 'New'), TicketModel::OPEN_STATUSES, true) => 'This ticket is already closed.',
            ! in_array((string) ($target['fields']['Status'] ?? 'New'), TicketModel::OPEN_STATUSES, true) => 'Ticket #' . $targetId . ' is closed. Merge into an open ticket.',
            default => null,
        };

        if ($error !== null) {
            return redirect()->to("/tickets/{$id}")->with('error', $error);
        }

        (new Duplicates())->merge($id, $fields, $targetId, $target['fields'], $user);

        return redirect()->to("/tickets/{$targetId}")->with('success', "Ticket #{$id} merged into this one and closed.");
    }

    // link or unlink two related tickets
    public function link(string $id)
    {
        $this->requireManageAccess($id);
        $targetId = (string) (int) ltrim(trim((string) $this->request->getPost('target_id')), '#');
        $target   = $targetId === '0' ? null : $this->ticketModel->getTicket($targetId);
        $user     = current_user();

        if ($target === null || $targetId === $id) {
            return redirect()->to("/tickets/{$id}")->with('error', 'Enter the number of another ticket.');
        }
        if (! $this->canManage($target['fields'], $user)) {
            return redirect()->to("/tickets/{$id}")->with('error', 'You can only link tickets you manage.');
        }

        if ($this->request->getPost('unlink') !== null) {
            Duplicates::unlink($id, $targetId, $user);

            return redirect()->to("/tickets/{$id}")->with('success', "Unlinked from #{$targetId}.");
        }

        $done = Duplicates::link($id, $targetId, $user);

        return redirect()->to("/tickets/{$id}")->with('success', $done ? "Linked to #{$targetId}." : "Already linked to #{$targetId}.");
    }

    // clears the possible duplicate notice
    public function dismissDuplicate(string $id)
    {
        $this->requireManageAccess($id);
        $this->ticketModel->update((int) $id, ['duplicate_of' => null]);
        Activity::ticket($id, 'not_duplicate');

        return redirect()->to("/tickets/{$id}")->with('success', 'Marked as not a duplicate.');
    }

    // staff add watchers by email
    public function addWatchers(string $id)
    {
        $fields = $this->requireManageAccess($id);
        $parsed = Watchers::parse((string) $this->request->getPost('watchers'));

        if ($parsed['emails'] === [] && $parsed['invalid'] === []) {
            return redirect()->to("/tickets/{$id}")->with('error', 'Enter at least one email.');
        }
        if ($parsed['invalid'] !== []) {
            return redirect()->to("/tickets/{$id}")->with('error', 'These are not email addresses: ' . implode(', ', array_slice($parsed['invalid'], 0, 5)) . '.');
        }
        if ($parsed['too_many'] || Watchers::count($id) + count($parsed['emails']) > Watchers::MAX) {
            return redirect()->to("/tickets/{$id}")->with('error', 'A ticket can have up to ' . Watchers::MAX . ' watchers.');
        }

        [$users, $missing] = Watchers::resolve($parsed['emails']);

        if ($missing !== []) {
            return redirect()->to("/tickets/{$id}")->with('error', 'No active account for: ' . implode(', ', array_slice($missing, 0, 5)) . '.');
        }

        $skipped = Watchers::add($id, $fields, $users, current_user());

        return redirect()->to("/tickets/{$id}")->with(
            $skipped === [] ? 'success' : 'error',
            $skipped === [] ? 'Watchers updated.' : 'Not added, as this ticket is confidential or full: ' . implode(', ', $skipped) . '.'
        );
    }

    public function removeWatcher(string $id, string $userId)
    {
        $this->requireManageAccess($id);
        Watchers::remove($id, (int) $userId, current_user());

        return redirect()->to("/tickets/{$id}")->with('success', 'Watcher removed.');
    }

    private function requireManageAccess(string $id): array
    {
        $ticket = $this->ticketModel->getTicket($id);
        if ($ticket === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $user   = current_user();
        $fields = $ticket['fields'] ?? $ticket;

        if (! $this->canManage($fields, $user)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return $fields;
    }

    public function updateStatus(string $id)
    {
        $fields = $this->requireManageAccess($id);

        $status = (string) $this->request->getPost('status');
        if (! in_array($status, TicketModel::STATUSES, true)) {
            return redirect()->back()->with('error', 'Invalid status.');
        }

        $from = (string) ($fields['Status'] ?? 'New');
        if ($from === $status) {
            return redirect()->to("/tickets/{$id}");
        }

        if ($from === 'Pending Approval' && ! Org::isSuperadmin(current_user())) {
            return redirect()->back()->with('error', 'This ticket is waiting for approval. It moves on once it is approved or rejected.');
        }

        $note = trim((string) $this->request->getPost('resolution_note'));

        if ($status === 'Resolved') {
            $meta = $this->metaModel->firstOrCreate($id);

            if (empty($meta['assigned_to'])) {
                return redirect()->back()->with('error', 'Assign the ticket to someone before resolving it.');
            }
            if (mb_strlen($note) < 10) {
                return redirect()->back()->with('error', 'Add a short resolution note (what fixed it) before resolving.');
            }
        }

        $this->applyStatus($id, $fields, $from, $status, $note);

        return redirect()->to("/tickets/{$id}")->with('success', 'Status updated.');
    }

    private function changeStatus(string $id, array $fields, string $from, string $to): void
    {
        (new TicketIntake())->changeStatus($id, $fields, $from, $to);
    }

    // shared by single and bulk status changes
    private function applyStatus(string $id, array $fields, string $from, string $to, string $note): void
    {
        if ($to === 'Resolved') {
            $this->ticketModel->update((int) $id, ['resolution_note' => mb_substr($note, 0, 2000)]);
        }

        $this->changeStatus($id, $fields, $from, $to);
    }

    // shared by single and bulk assignment
    private function applyAssignee(string $id, array $fields, int $assignedTo, ?string $name = null): void
    {
        $this->metaModel->updateForTicket($id, ['assigned_to' => $assignedTo > 0 ? $assignedTo : null]);

        $name ??= $assignedTo > 0 ? (string) ($this->userModel->find($assignedTo)['name'] ?? '') : '';
        Activity::ticket($id, 'assigned', null, $name);

        if ($assignedTo > 0) {
            AutoAssign::touch($assignedTo);
            (new Notifier())->assigned($id, $fields, $assignedTo);
        }
    }

    // bulk priority change, same log and sla restamp as the ticket page
    private function applyPriority(string $id, array $fields, string $from, string $priority): void
    {
        $this->metaModel->updateForTicket($id, ['priority' => $priority]);
        Activity::ticket($id, 'priority', $from, $priority);
        (new Sla())->stamp($id, (string) ($fields['CreatedAt'] ?? date('Y-m-d H:i:s')), (string) ($fields['Category'] ?? 'Common Request'), $priority);
    }

    public function sendSurvey(string $id, array $fields): void
    {
        (new TicketIntake())->sendSurvey($id, $fields);
    }

    public function feedback(string $id)
    {
        $db    = \Config\Database::connect();
        $row   = $db->table('ticket_feedback')->where('ticket_id', $id)->get()->getRowArray();
        $token = (string) $this->request->getGet('t');
        $score = (int) $this->request->getGet('r');

        if ($row === null || $token === '' || ! hash_equals((string) $row['token'], $token) || $score < 1 || $score > 5) {
            return redirect()->to("/tickets/{$id}")->with('error', 'That feedback link is not valid any more.');
        }

        $db->table('ticket_feedback')->where('id', $row['id'])->update(['rating' => $score, 'token' => null, 'created_at' => date('Y-m-d H:i:s')]);
        Activity::ticket($id, 'feedback', null, (string) $score);

        return redirect()->to("/tickets/{$id}")->with('success', 'Thanks for rating the help.');
    }

    public function approval(string $id)
    {
        $ticket = $this->ticketModel->getTicket($id);
        if ($ticket === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $fields  = $ticket['fields'];
        $approve = $this->request->getPost('decision') === 'approve';
        $note    = trim((string) $this->request->getPost('note'));

        if (! $approve && mb_strlen($note) < 5) {
            return redirect()->back()->with('error', 'Add a short reason when rejecting.');
        }

        $result = (new Approvals())->decide($id, $fields, current_user(), $approve, $note);

        if ($result === null) {
            return redirect()->back()->with('error', 'You cannot approve this step.');
        }

        return redirect()->to("/tickets/{$id}")->with('success', $approve ? 'Approved.' : 'Rejected. The requester has been told.');
    }

    // impact and urgency matrix from the two intake questions
    public static function priorityFrom(string $people, string $stopped): ?string
    {
        return TicketIntake::priorityFrom($people, $stopped);
    }

    private function service(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        return \Config\Database::connect()->table('services')->where(['service_key' => $key, 'is_active' => 1])->get()->getRowArray();
    }

    private function isConfidential(string $department, string $role): bool
    {
        return TicketIntake::isConfidential($department, $role);
    }

    private function canned(array $fields): array
    {
        $departmentId = (new \App\Models\DepartmentModel())->idFor(ticket_department($fields));

        return \Config\Database::connect()->table('canned_responses')
            ->groupStart()->where('department_id', null)->orWhere('department_id', $departmentId)->groupEnd()
            ->orderBy('title', 'ASC')->get()->getResultArray();
    }

    public function updateMeta(string $id)
    {
        $fields = $this->requireManageAccess($id);

        $priority   = (string) $this->request->getPost('priority');
        $dueDate    = trim((string) $this->request->getPost('due_date'));
        $assignedTo = (int) $this->request->getPost('assigned_to');

        if (! in_array($priority, priorities(), true)) {
            return redirect()->back()->with('error', 'Invalid priority.');
        }

        if ($dueDate !== '' && ! is_ymd_date($dueDate)) {
            return redirect()->back()->with('error', 'That due date is not a real date.');
        }

        $before  = $this->metaModel->firstOrCreate($id);
        $current = (int) ($before['assigned_to'] ?? 0);

        if ($assignedTo > 0 && $assignedTo !== $current
            && ! in_array($assignedTo, array_map('intval', array_column(Org::assignableStaff($fields), 'id')), true)) {
            return redirect()->back()->with('error', 'That person does not handle this team\'s tickets.');
        }

        $update = ['priority' => $priority];

        if ($dueDate !== '' && $dueDate !== (string) ($before['due_date'] ?? '')) {
            $update['due_date'] = $dueDate;
            $update['due_at']   = $dueDate . ' 17:00:00';
            Activity::ticket($id, 'due', $before['due_date'] ?? null, format_day($dueDate));
        }

        $this->metaModel->updateForTicket($id, $update);

        if ($priority !== ($before['priority'] ?? 'Medium')) {
            Activity::ticket($id, 'priority', $before['priority'] ?? null, $priority);
        }

        if ($assignedTo !== $current) {
            $this->applyAssignee($id, $fields, $assignedTo);
        }

        $category = trim((string) $this->request->getPost('category'));

        if (in_array($category, ticket_categories(), true) && $category !== (string) ($fields['Category'] ?? '')) {
            $this->ticketModel->update((int) $id, ['category' => $category]);
            Activity::ticket($id, 'category', $fields['Category'] ?? null, $category);
        }

        if (($dueDate === '' || $dueDate === (string) ($before['due_date'] ?? ''))
            && ($priority !== ($before['priority'] ?? 'Medium') || (in_array($category, ticket_categories(), true) && $category !== (string) ($fields['Category'] ?? '')))) {
            (new Sla())->stamp($id, (string) ($fields['CreatedAt'] ?? date('Y-m-d H:i:s')), in_array($category, ticket_categories(), true) ? $category : (string) ($fields['Category'] ?? 'Common Request'), $priority);
        }

        $moved = $this->moveToTeam($id, $fields, (string) $this->request->getPost('owning_team'));

        if ($moved !== null) {
            $after = $this->ticketModel->getTicket($id)['fields'] ?? [];

            if (! $this->canAccess($after, current_user())) {
                return redirect()->to('/tickets')->with('success', "Ticket #{$id} moved to {$moved}. It is with that team now.");
            }

            return redirect()->to("/tickets/{$id}")->with('success', "Ticket details updated and moved to {$moved}.");
        }

        return redirect()->to("/tickets/{$id}")->with('success', 'Ticket details updated.');
    }

    private function moveToTeam(string $id, array $fields, string $posted): ?string
    {
        if ($posted === '') {
            return null;
        }

        [$department, $role] = array_pad(explode('||', $posted, 2), 2, '');

        if ($department === '') {
            return null;
        }

        $routing = new RoutingDirectory();
        $current = RoutingDirectory::owner($fields);
        $target  = ['department' => $department, 'role' => $role];

        $unchanged = strcasecmp($department, $current['department']) === 0 && strcasecmp($role, $current['role']) === 0;

        if ($unchanged || ! $routing->isTeam($department, $role)) {
            return null;
        }

        $user = current_user();
        $this->ticketModel->applyRoute($id, $department, $role, 'moved by ' . $user['name'] . ' from ' . RoutingDirectory::label($current));
        $this->ticketModel->update((int) $id, ['is_confidential' => $this->isConfidential($department, $role) ? 1 : 0]);
        Activity::ticket($id, 'team', RoutingDirectory::label($current), RoutingDirectory::label($target));
        (new AutoAssign())->run($id, $this->ticketModel->getTicket($id)['fields'] ?? []);

        return RoutingDirectory::label($target);
    }

    public function postMessage(string $id)
    {
        $ticket = $this->ticketModel->getTicket($id);
        if ($ticket === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $user   = current_user();
        $fields = $ticket['fields'] ?? $ticket;

        if (! $this->canAccess($fields, $user)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $body = trim((string) $this->request->getPost('body'));
        if ($body === '') {
            return redirect()->back()->with('error', 'Message cannot be empty.');
        }

        if (in_array((string) ($fields['Status'] ?? ''), ['Closed', 'Cancelled'], true)) {
            return redirect()->to("/tickets/{$id}")->with('error', 'This ticket is closed. Raise a new ticket if it comes back.');
        }

        if (! Org::canReplyTicket($user, $fields)) {
            return redirect()->to("/tickets/{$id}")->with('error', 'You are watching this ticket. Only the requester and the team can reply.');
        }

        $internal = $this->canManage($fields, $user) && $this->request->getPost('is_internal') !== null;

        $isSolution = ! $internal
            && $this->canManage($fields, $user)
            && $this->request->getPost('is_solution') !== null
            && mb_strlen($body) >= 25;

        $selfApproved = $isSolution && KnowledgeReview::canReview($user, ticket_department($fields));

        $messageId = $this->messageModel->post(
            $id,
            $user['id'],
            $user['name'],
            $user['role'],
            $body,
            TicketMessageModel::KIND_MESSAGE,
            null,
            $isSolution,
            $internal
        );

        if ($messageId === false) {
            return redirect()->back()->with('error', 'Your message could not be saved. Please try again.');
        }

        if ($selfApproved) {
            $this->messageModel->reviewSolution((int) $messageId, TicketMessageModel::SOLUTION_APPROVED, (int) $user['id']);
        } elseif ($isSolution) {
            (new Notifier())->solutionPending($id, $fields, $user);
        }

        $mentioned = (new TicketIntake())->afterReply($id, $fields, $user, $body, $internal);

        $redirect = redirect()->to("/tickets/{$id}#conversation");

        if ($mentioned !== []) {
            $redirect = $redirect->with('success', 'Notified ' . implode(', ', array_column($mentioned, 'name')) . '.');
        }

        if ($selfApproved) {
            $redirect = $redirect->with('success', 'Saved as the solution. The assistant can reuse it on similar tickets.');
        } elseif ($isSolution) {
            $redirect = $redirect->with('success', 'Marked as the solution. A team lead reviews it before the assistant reuses it.');
        }

        if (! $internal && $this->assistantShouldAnswer($fields, $user)) {
            $redirect = $redirect->with('assist_message_id', (int) $messageId);
        }

        return $redirect;
    }

    public function assist(string $id)
    {
        $ticket = $this->ticketModel->getTicket($id);
        if ($ticket === null) {
            return $this->assistError('Ticket not found.', 404);
        }

        $user   = current_user();
        $fields = $ticket['fields'] ?? $ticket;

        if (! $this->canAccess($fields, $user)) {
            return $this->assistError('Ticket not found.', 404);
        }

        $messageId = (int) ($this->request->getPost('message_id') ?? 0);
        $message   = $messageId > 0 ? $this->messageModel->find($messageId) : null;

        if ($message === null
            || (string) $message['ticket_id'] !== (string) $id
            || (int) ($message['user_id'] ?? 0) !== (int) $user['id']
        ) {
            return $this->assistError('Nothing to answer.', 422);
        }

        if (! $this->assistantShouldAnswer($fields, $user)) {
            return $this->assistNoop();
        }

        $latest = $this->messageModel->latestForTicket($id);
        if ($latest !== null && (string) $latest['author_role'] === TicketAssistant::ROLE) {
            return $this->assistNoop();
        }

        ignore_user_abort(true);

        $session = session();
        if (method_exists($session, 'close')) {
            $session->close();
        }

        $result = $this->assistant->respond($id, $fields, $user, (string) $message['body'], $messageId);

        if ($result === null) {
            return $this->assistNoop();
        }

        return $this->response->setJSON([
            'ok'      => true,
            'message' => $this->renderMessage($result['message'], $fields),

            'changed' => array_keys($result['changes']),
            'state'   => $result['state'],
        ]);
    }

    public function updates(string $id)
    {
        $ticket = $this->ticketModel->getTicket($id);
        if ($ticket === null) {
            return $this->assistError('Ticket not found.', 404);
        }

        $fields = $ticket['fields'] ?? $ticket;

        if (! $this->canAccess($fields, current_user())) {
            return $this->assistError('Ticket not found.', 404);
        }

        $after = max(0, (int) $this->request->getGet('after'));

        $newer = array_filter(
            $this->messageModel->forTicket($id, $this->canManage($fields, current_user())),
            static fn ($m) => (int) $m['id'] > $after
        );

        return $this->response->setJSON([
            'ok'       => true,
            'messages' => array_values(array_map(fn ($m) => [
                'id'   => (int) $m['id'],
                'role' => (string) $m['author_role'],
                'html' => $this->renderMessage($m, $fields),
            ], $newer)),
        ]);
    }

    private function assistantShouldAnswer(array $fields, array $user): bool
    {
        return $this->assistant->isEnabled()
            && $this->assistant->isRequester($fields, $user)
            && ! in_array($fields['Status'] ?? 'New', ['Closed', 'Cancelled', 'Pending Approval'], true);
    }

    private function renderMessage(array $message, ?array $fields = null): string
    {
        if ($fields !== null && ($user = current_user()) !== null) {
            $message = $this->withVotes([$message], $fields, $user)[0];
        }

        return render_message($message);
    }

    // adds the requester's vote buttons to assistant answers
    private function withVotes(array $messages, array $fields, array $user): array
    {
        if (! $this->assistant->isRequester($fields, $user)) {
            return $messages;
        }

        $ids = [];
        foreach ($messages as $m) {
            if (AiDeflection::rateable($m)) {
                $ids[] = (int) $m['id'];
            }
        }

        $votes = (new AiFeedbackModel())->votesBy((int) $user['id'], $ids);

        foreach ($messages as &$m) {
            if (AiDeflection::rateable($m)) {
                $m['can_rate'] = true;
                $m['my_vote']  = $votes[(int) $m['id']] ?? null;
            }
        }
        unset($m);

        return $messages;
    }

    public function rateAnswer(string $id, string $messageId)
    {
        $ticket = $this->ticketModel->getTicket($id);
        $user   = current_user();
        $fields = $ticket === null ? [] : ($ticket['fields'] ?? $ticket);

        if ($ticket === null || ! $this->canAccess($fields, $user) || ! $this->assistant->isRequester($fields, $user)) {
            return $this->rateResult($id, false, 'Only the person who raised the ticket can rate answers.', 404);
        }

        $message = $this->messageModel->find((int) $messageId);

        if ($message === null || (string) $message['ticket_id'] !== (string) $id || ! AiDeflection::rateable($message)) {
            return $this->rateResult($id, false, 'That answer cannot be rated.', 422);
        }

        $helpful = (string) $this->request->getPost('helpful') === '1';
        $saved   = (new AiFeedbackModel())->vote((int) $message['id'], (int) $user['id'], $helpful);

        return $saved
            ? $this->rateResult($id, true, 'Thanks for the feedback.', 200, $helpful)
            : $this->rateResult($id, false, 'Your feedback could not be saved. Please try again.', 500);
    }

    private function rateResult(string $id, bool $ok, string $text, int $status, ?bool $helpful = null)
    {
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode($status)->setJSON(['ok' => $ok, 'message' => $text, 'helpful' => $helpful]);
        }

        return redirect()->to('/tickets/' . rawurlencode($id) . '#conversation')->with($ok ? 'success' : 'error', $text);
    }

    private function assistNoop(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setJSON(['ok' => true, 'message' => null, 'changed' => [], 'state' => 'OPEN']);
    }

    private function assistError(string $message, int $status): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['ok' => false, 'error' => $message]);
    }
}
