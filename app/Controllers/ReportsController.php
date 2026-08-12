<?php

namespace App\Controllers;

use App\Models\TicketModel;
use App\Models\TicketMetaModel;
use App\Models\UserModel;

class ReportsController extends BaseController
{
    protected TicketModel $ticketModel;
    protected TicketMetaModel $metaModel;
    protected UserModel $userModel;

    public function __construct()
    {
        $this->ticketModel = new TicketModel();
        $this->metaModel   = new TicketMetaModel();
        $this->userModel   = new UserModel();
    }

    public function index()
    {
        $department = (string) ($this->request->getGet('department') ?? '');
        $dateFrom   = (string) ($this->request->getGet('date_from') ?? '');
        $dateTo     = (string) ($this->request->getGet('date_to') ?? '');

        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $tickets = $this->ticketModel->getAllTickets();

        if ($department !== '') {
            $tickets = array_values(array_filter(
                $tickets,
                fn ($t) => resolve_base_department(ticket_department($t['fields'] ?? [])) === $department
            ));
        }

        if ($dateFrom !== '') {
            $tickets = array_values(array_filter(
                $tickets,
                fn ($t) => date('Y-m-d', strtotime($t['fields']['CreatedAt'] ?? 'now')) >= $dateFrom
            ));
        }

        if ($dateTo !== '') {
            $tickets = array_values(array_filter(
                $tickets,
                fn ($t) => date('Y-m-d', strtotime($t['fields']['CreatedAt'] ?? 'now')) <= $dateTo
            ));
        }

        $ticketIds = array_map(fn ($t) => (string) ($t['id'] ?? ''), $tickets);
        $metaById  = $this->metaModel->forTickets($ticketIds);

        $agents = $this->userModel->where('role', UserModel::ROLE_AGENT)->findAll();
        $agentsById = [];
        foreach ($agents as $agent) {
            $agentsById[$agent['id']] = $agent;
        }

        $statusCounts     = ['New' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0];
        $priorityCounts   = array_fill_keys(priorities(), 0);

        $departmentCounts = $department !== '' ? [$department => 0] : array_fill_keys(departments(), 0);
        $categoryCounts   = [];
        $volumeByDay      = [];
        $agentWorkload    = [];
        $overdueCount     = 0;
        $unassignedOpen   = 0;
        $today            = date('Y-m-d');

        $volumeRangeTruncated = false;
        if ($dateFrom !== '' || $dateTo !== '') {
            $rangeEnd   = $dateTo !== '' ? $dateTo : $today;
            $rangeStart = $dateFrom !== '' ? $dateFrom : date('Y-m-d', strtotime($rangeEnd . ' -13 days'));

            $spanDays = (int) ((strtotime($rangeEnd) - strtotime($rangeStart)) / 86400);
            if ($spanDays > 59) {
                $rangeStart           = date('Y-m-d', strtotime($rangeEnd . ' -59 days'));
                $volumeRangeTruncated = true;
            }
        } else {
            $rangeEnd   = $today;
            $rangeStart = date('Y-m-d', strtotime('-13 days'));
        }

        $cursor = $rangeStart;
        while ($cursor <= $rangeEnd) {
            $volumeByDay[$cursor] = 0;
            $cursor               = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        foreach ($tickets as $t) {
            $f    = $t['fields'] ?? [];
            $id   = (string) ($t['id'] ?? '');
            $meta = $metaById[$id] ?? ['priority' => 'Medium', 'due_date' => null, 'assigned_to' => null];

            $status = $f['Status'] ?? 'New';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }

            $priority = $meta['priority'] ?? 'Medium';
            if (isset($priorityCounts[$priority])) {
                $priorityCounts[$priority]++;
            }

            $dept = resolve_base_department(ticket_department($f));
            if ($dept !== '') {
                $departmentCounts[$dept] = ($departmentCounts[$dept] ?? 0) + 1;
            }

            $category = $f['Category'] ?? 'Uncategorized';
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            $day = date('Y-m-d', strtotime($f['CreatedAt'] ?? 'now'));
            if (isset($volumeByDay[$day])) {
                $volumeByDay[$day]++;
            }

            $isOpen = ! in_array($status, ['Resolved', 'Closed'], true);

            if ($isOpen && ! empty($meta['due_date']) && $meta['due_date'] < $today) {
                $overdueCount++;
            }

            if ($isOpen && empty($meta['assigned_to'])) {
                $unassignedOpen++;
            }

            if (! empty($meta['assigned_to']) && isset($agentsById[$meta['assigned_to']])) {
                $agentId = $meta['assigned_to'];
                if (! isset($agentWorkload[$agentId])) {
                    $agentWorkload[$agentId] = ['name' => $agentsById[$agentId]['name'], 'department' => $agentsById[$agentId]['department'], 'open' => 0, 'total' => 0];
                }
                $agentWorkload[$agentId]['total']++;
                if ($isOpen) {
                    $agentWorkload[$agentId]['open']++;
                }
            }
        }

        arsort($departmentCounts);
        arsort($categoryCounts);
        $topCategories = array_slice($categoryCounts, 0, 6, true);

        usort($agentWorkload, fn ($a, $b) => $b['open'] <=> $a['open']);

        return view('reports/index', [
            'total'            => count($tickets),
            'openCount'        => $statusCounts['New'] + $statusCounts['In Progress'],
            'resolvedCount'    => $statusCounts['Resolved'] + $statusCounts['Closed'],
            'overdueCount'     => $overdueCount,
            'unassignedOpen'   => $unassignedOpen,
            'statusCounts'     => $statusCounts,
            'priorityCounts'   => $priorityCounts,
            'departmentCounts' => $departmentCounts,
            'topCategories'    => $topCategories,
            'volumeByDay'      => $volumeByDay,
            'agentWorkload'    => $agentWorkload,
            'department'       => $department,
            'dateFrom'         => $dateFrom,
            'dateTo'           => $dateTo,
            'volumeRangeStart' => $rangeStart,
            'volumeRangeEnd'   => $rangeEnd,
            'volumeRangeTruncated' => $volumeRangeTruncated,
        ]);
    }
}
