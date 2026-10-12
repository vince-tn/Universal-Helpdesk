<?php

namespace App\Controllers;

use App\Libraries\Html;
use App\Libraries\Mentions;
use App\Libraries\Org;
use App\Libraries\PriorCases;
use App\Libraries\RoutingDirectory;
use App\Libraries\TicketAssistant;
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

            $t['department'] = ticket_department($t['fields'] ?? []);
            $t['meta'] = [
                'priority'      => $meta['priority'] ?? 'Medium',
                'due_date'      => $meta['due_date'] ?? null,
                'assigned_to'   => $meta['assigned_to'] ?? null,
                'assigned_name' => isset($meta['assigned_to']) ? ($agentNames[$meta['assigned_to']] ?? null) : null,
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

        $perPage     = 8;
        $page        = max(1, (int) ($this->request->getGet('page') ?? 1));
        $totalFound  = count($tickets);
        $totalPages  = max(1, (int) ceil($totalFound / $perPage));
        $page        = min($page, $totalPages);
        $pageTickets = array_slice($tickets, ($page - 1) * $perPage, $perPage);

        $statusCounts = ['New' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0];
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
        ]));
    }

    public function export()
    {
        $user    = current_user();
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
        $filename = 'tickets_export_' . date('Y-m-d_His');

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

        return view('tickets/create', [
            'user'  => current_user(),
            'roles' => array_values($active),
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

        $error = match (true) {
            ! in_array($department, departments(), true)
                => 'Choose the department you are raising this from.',
            mb_strlen($text) < 10
                => 'Please describe your request in a bit more detail - at least a sentence.',
            mb_strlen($text) > 5000
                => 'That request is very long. Please keep it under 5000 characters and attach the detail instead.',
            default => null,
        };

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

        return redirect()->to("/tickets/{$id}")->with('success', 'Ticket submitted.');
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

        $text  = (string) ($fields['Request'] ?? '');
        $prior = $this->ticketModel->similarResolved($text, $id);

        $result = $this->n8nService->classify([
            'Requester Name'        => $fields['Requester'] ?? '',
            'Requester Email'       => $fields['Requester Email'] ?? '',
            'Submitting Department' => $fields['Submitting Department'] ?? '',
            'Submitting Role'       => $fields['Submitting Role'] ?? '',
            'Request'               => $text,
        ], $this->priorCases->context($prior));

        if ($result === null) {
            $stored = $this->messageModel->hasAssistantMessage($id)
                ? null
                : $this->assistant->opening($id, [], '');

            return $this->response->setJSON([
                'ok'      => true,
                'changed' => false,
                'message' => $stored === null ? null : $this->renderMessage($stored),
            ]);
        }

        $output = $result['output'] ?? [];

        $this->ticketModel->replaceClassification($id, $output, $result['ai_source'] ?? 'Local');
        $this->metaModel->updateForTicket($id, TicketMetaModel::fromClassification($output));

        $route = (new RoutingDirectory())->reroute($id, $result['routing'] ?? [], $output, $text);

        if ($route !== null) {
            $output['responsible_department'] = $route['department'];
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
            'message' => $stored === null ? null : $this->renderMessage($stored),
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
            'messages'   => $this->messageModel->forTicket($id),
            'canManage'  => $this->canManage($fields, $user),
            'awaitingAi' => $this->ticketModel->awaitsClassification($fields),
            'teams'      => $this->canManage($fields, $user) ? (new RoutingDirectory())->entries() : [],
        ]);
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
        $this->requireManageAccess($id);

        $status = $this->request->getPost('status');
        if (! in_array($status, TicketModel::STATUSES, true)) {
            return redirect()->back()->with('error', 'Invalid status.');
        }

        $this->ticketModel->updateStatus($id, $status);

        return redirect()->to("/tickets/{$id}")->with('success', 'Status updated.');
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

        $current = (int) ($this->metaModel->firstOrCreate($id)['assigned_to'] ?? 0);

        if ($assignedTo > 0 && $assignedTo !== $current
            && ! in_array($assignedTo, array_map('intval', array_column(Org::assignableStaff($fields), 'id')), true)) {
            return redirect()->back()->with('error', 'That person does not handle this team\'s tickets.');
        }

        $this->metaModel->updateForTicket($id, [
            'priority'    => $priority,
            'due_date'    => $dueDate !== '' ? $dueDate : null,
            'assigned_to' => $assignedTo > 0 ? $assignedTo : null,
        ]);

        $category = trim((string) $this->request->getPost('category'));
        $tat      = trim((string) $this->request->getPost('suggested_tat'));

        $tags = [];
        if (in_array($category, ticket_categories(), true)) {
            $tags['category'] = $category;
        }
        if ($tat !== '' && mb_strlen($tat) <= 100) {
            $tags['suggested_tat'] = $tat;
        }

        if ($tags !== []) {
            $this->ticketModel->update((int) $id, $tags);
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

        $isSolution = $this->canManage($fields, $user)
            && $this->request->getPost('is_solution') !== null
            && mb_strlen($body) >= 25;

        $messageId = $this->messageModel->post(
            $id,
            $user['id'],
            $user['name'],
            $user['role'],
            $body,
            TicketMessageModel::KIND_MESSAGE,
            null,
            $isSolution
        );

        if ($messageId === false) {
            return redirect()->back()->with('error', 'Your message could not be saved. Please try again.');
        }

        $mentioned = (new Mentions())->notify(
            $body,
            $user,
            $id,
            (string) ($fields['Request Title'] ?? $fields['Title'] ?? 'a ticket'),
            Org::ticketAudience($fields)
        );

        $redirect = redirect()->to("/tickets/{$id}#conversation");

        if ($mentioned !== []) {
            $redirect = $redirect->with('success', 'Notified ' . implode(', ', array_column($mentioned, 'name')) . '.');
        }

        if ($isSolution) {
            $redirect = $redirect->with('success', 'Saved as the solution. The assistant can reuse it on similar tickets.');
        }

        if ($this->assistantShouldAnswer($fields, $user)) {
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
            'message' => $this->renderMessage($result['message']),

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
            $this->messageModel->forTicket($id),
            static fn ($m) => (int) $m['id'] > $after
        );

        return $this->response->setJSON([
            'ok'       => true,
            'messages' => array_values(array_map(fn ($m) => [
                'id'   => (int) $m['id'],
                'role' => (string) $m['author_role'],
                'html' => $this->renderMessage($m),
            ], $newer)),
        ]);
    }

    private function assistantShouldAnswer(array $fields, array $user): bool
    {
        return $this->assistant->isEnabled()
            && $this->assistant->isRequester($fields, $user)
            && ($fields['Status'] ?? 'New') !== 'Closed';
    }

    private function renderMessage(array $message): string
    {
        return render_message($message);
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
