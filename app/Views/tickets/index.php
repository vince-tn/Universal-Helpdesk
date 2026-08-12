<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    $user = current_user();

    function statusPillClass(string $status): string {
        return match ($status) {
            'New' => 'pill-status-new',
            'In Progress' => 'pill-status-in-progress',
            'Resolved' => 'pill-status-resolved',
            'Closed' => 'pill-status-closed',
            default => 'pill-status-new',
        };
    }
    function priorityPillClass(string $priority): string {
        return match ($priority) {
            'Low' => 'pill-priority-low',
            'High' => 'pill-priority-high',
            'Urgent' => 'pill-priority-urgent',
            default => 'pill-priority-medium',
        };
    }
    function sourcePillClass(string $source): string {
        return $source === 'Gemini' ? 'pill-source-gemini' : 'pill-source-local';
    }

    function sortLink(string $field, string $label, string $currentSort, array $params): string {
        $next = $currentSort === "{$field}_desc" ? "{$field}_asc" : "{$field}_desc";
        $qs = http_build_query(array_merge($params, ['sort' => $next]));
        $active = str_starts_with($currentSort, $field);
        return '<a href="?' . esc($qs) . '">' . esc($label) . ($active ? icon('sort', 13) : '') . '</a>';
    }

    $baseParams = ['q' => $q, 'status' => $status, 'category' => $category, 'priority' => $priority, 'department' => $department];
    $exportParams = array_merge($baseParams, ['sort' => $sort]);
?>

<div class="page-header">
    <div>
        <h1><?= $user['role'] === 'requester' ? 'My Tickets' : 'Ticket Dashboard' ?></h1>
        <div class="subtitle">
            <?= $user['role'] === 'requester'
                ? 'Everything you\'ve submitted, and its current status.'
                : ($user['role'] === 'superadmin' ? 'All incoming requests across every department, auto-classified and routed.' : 'Requests routed to ' . esc($user['department']) . '.') ?>
        </div>
    </div>
    <div class="table-actions">
        <div class="export-group">
            <span class="export-label"><?= icon('download', 14) ?> Export</span>
            <a href="/tickets/export?<?= esc(http_build_query(array_merge($exportParams, ['format' => 'csv']))) ?>">CSV</a>
            <a href="/tickets/export?<?= esc(http_build_query(array_merge($exportParams, ['format' => 'xls']))) ?>">Excel</a>
            <a href="/tickets/export?<?= esc(http_build_query(array_merge($exportParams, ['format' => 'json']))) ?>">JSON</a>
        </div>
        <a href="/tickets/new" class="btn"><?= icon('plus', 16) ?> New Ticket</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="stat-cards">
    <div class="stat-card">
        <div class="value"><?= $total ?></div>
        <div class="label">Total Tickets</div>
    </div>
    <div class="stat-card accent-new">
        <div class="value"><?= $statusCounts['New'] ?></div>
        <div class="label">New</div>
    </div>
    <div class="stat-card accent-progress">
        <div class="value"><?= $statusCounts['In Progress'] ?></div>
        <div class="label">In Progress</div>
    </div>
    <div class="stat-card accent-done">
        <div class="value"><?= $statusCounts['Resolved'] + $statusCounts['Closed'] ?></div>
        <div class="label">Resolved / Closed</div>
    </div>
</div>

<form class="toolbar" method="get" action="/tickets">
    <div class="search-field">
        <?= icon('search', 16) ?>
        <input type="search" name="q" placeholder="Search title, requester, email…" value="<?= esc($q) ?>">
    </div>
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (['New', 'In Progress', 'Resolved', 'Closed'] as $opt): ?>
            <option value="<?= $opt ?>" <?= $status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach ?>
    </select>
    <select name="category">
        <option value="">All categories</option>
        <?php foreach ($categories as $opt): ?>
            <option value="<?= esc($opt) ?>" <?= $category === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
        <?php endforeach ?>
    </select>
    <select name="priority">
        <option value="">All priorities</option>
        <?php foreach (priorities() as $opt): ?>
            <option value="<?= $opt ?>" <?= $priority === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach ?>
    </select>
    <?php if ($user['role'] === 'superadmin'): ?>
        <select name="department">
            <option value="">All departments</option>
            <?php foreach (departments() as $opt): ?>
                <option value="<?= $opt ?>" <?= $department === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach ?>
        </select>
    <?php endif ?>
    <input type="hidden" name="sort" value="<?= esc($sort) ?>">
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
    <?php if ($q || $status || $category || $priority || $department): ?>
        <a href="/tickets" class="toolbar-clear">Clear filters</a>
    <?php endif ?>
</form>

<div class="result-count"><?= $totalFound ?> ticket<?= $totalFound === 1 ? '' : 's' ?> found</div>

<div class="card">
    <?php if (empty($tickets)): ?>
        <div class="empty-state">
            <?= icon('inbox', 40) ?>
            <div>No tickets match right now. <a href="/tickets/new">Submit a new one</a>.</div>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= sortLink('title', 'Title', $sort, $baseParams) ?></th>
                    <th>Department <span class="badge-ai sm"><?= icon('sparkle', 10) ?> AI</span></th>
                    <th>Category <span class="badge-ai sm"><?= icon('sparkle', 10) ?> AI</span></th>
                    <th>Requester</th>
                    <th><?= sortLink('priority', 'Priority', $sort, $baseParams) ?></th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th><?= sortLink('created', 'Created', $sort, $baseParams) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $t): $f = $t['fields']; $m = $t['meta']; ?>
                    <tr class="ticket-row" onclick="window.location='/tickets/<?= esc($t['id'] ?? '') ?>'">
                        <td><a class="ticket-title" href="/tickets/<?= esc($t['id'] ?? '') ?>"><?= esc($f['Request Title'] ?? $f['Title'] ?? 'Untitled') ?></a></td>
                        <td><?= $t['department'] ? '<span class="badge-outline">' . esc($t['department']) . '</span>' : '<span class="cell-muted">—</span>' ?></td>
                        <td class="cell-muted"><?= esc($f['Category'] ?? '—') ?></td>
                        <td class="cell-muted"><?= esc($f['Requester'] ?? '—') ?></td>
                        <td><span class="pill <?= priorityPillClass($m['priority']) ?>"><span class="dot"></span><?= esc($m['priority']) ?></span></td>
                        <td><span class="pill <?= statusPillClass($f['Status'] ?? 'New') ?>"><?= esc($f['Status'] ?? 'New') ?></span></td>
                        <td class="cell-muted"><?= $m['assigned_name'] ? esc($m['assigned_name']) : '—' ?></td>
                        <td class="cell-muted"><?= esc(date('M j, g:i A', strtotime($f['CreatedAt'] ?? 'now'))) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= esc(http_build_query(array_merge($baseParams, ['sort' => $sort, 'page' => $page - 1]))) ?>"><?= icon('chevron-left', 14) ?></a>
        <?php else: ?>
            <span class="disabled"><?= icon('chevron-left', 14) ?></span>
        <?php endif ?>

        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="?<?= esc(http_build_query(array_merge($baseParams, ['sort' => $sort, 'page' => $p]))) ?>"><?= $p ?></a>
            <?php endif ?>
        <?php endfor ?>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
