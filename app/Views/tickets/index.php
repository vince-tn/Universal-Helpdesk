<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    $user = current_user();

    function statusPillClass(string $status): string {
        return match ($status) {
            'New' => 'pill-status-new',
            'In Progress', 'Reopened' => 'pill-status-in-progress',
            'Awaiting Requester', 'On Hold', 'Pending Approval' => 'pill-status-waiting',
            'Resolved' => 'pill-status-resolved',
            'Closed', 'Cancelled' => 'pill-status-closed',
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
        return in_array($source, ['Gemini', 'OpenRouter'], true) ? 'pill-source-gemini' : 'pill-source-local';
    }

    // sortable header with an arrow for the current direction
    function sortHeader(string $field, string $label, string $currentSort, array $params): string {
        $state = $currentSort === "{$field}_asc" ? 'ascending' : ($currentSort === "{$field}_desc" ? 'descending' : 'none');
        $next  = $state === 'descending' ? "{$field}_asc" : "{$field}_desc";
        $qs    = http_build_query(array_merge(array_filter($params, static fn ($v) => $v !== ''), ['sort' => $next]));
        $arrow = match ($state) {
            'ascending'  => icon('sort-asc', 13),
            'descending' => icon('sort-desc', 13),
            default      => icon('sort', 13),
        };
        return '<th aria-sort="' . $state . '"><a class="sort-link' . ($state === 'none' ? '' : ' is-active') . '" href="?' . esc($qs) . '" title="Sort by ' . esc(strtolower($label)) . '">'
            . esc($label) . '<span class="sort-arrow" aria-hidden="true">' . $arrow . '</span></a></th>';
    }

    $baseParams = ['q' => $q, 'status' => $status, 'category' => $category, 'priority' => $priority, 'department' => $department, 'view' => $view];

    $views = $user['role'] === 'requester'
        ? ['' => 'All my tickets']
        : ['' => 'All', 'assigned' => 'Assigned to me', 'team' => "My team's queue", 'unassigned' => 'Unassigned', 'breaching' => 'SLA at risk', 'approval' => 'Waiting approval', 'mine' => 'Raised by me'];
    $exportParams = array_merge($baseParams, ['sort' => $sort]);
    $showBulk     = ! empty($canBulk) && in_array(true, array_map(static fn ($t) => ! empty($t['can_manage']), $tickets), true);
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
        <?php if (\App\Libraries\ExportGuard::allowed($user)): ?>
        <div class="export-group">
            <span class="export-label"><?= icon('download', 14) ?> Export</span>
            <a href="/tickets/export?<?= esc(http_build_query(array_merge($exportParams, ['format' => 'csv']))) ?>">CSV</a>
            <a href="/tickets/export?<?= esc(http_build_query(array_merge($exportParams, ['format' => 'xls']))) ?>">Excel</a>
            <a href="/tickets/export?<?= esc(http_build_query(array_merge($exportParams, ['format' => 'json']))) ?>">JSON</a>
        </div>
        <?php endif ?>
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

<?php if (count($views) > 1): ?>
    <nav class="queue-tabs" aria-label="Ticket queues">
        <?php foreach ($views as $key => $label): ?>
            <a href="?<?= esc(http_build_query(array_merge($baseParams, ['view' => $key, 'page' => 1]))) ?>" class="<?= $view === $key ? 'active' : '' ?>"><?= esc($label) ?></a>
        <?php endforeach ?>
    </nav>
<?php endif ?>

<form class="toolbar" method="get" action="/tickets" data-instant>
    <input type="hidden" name="view" value="<?= esc($view) ?>">
    <div class="search-field">
        <?= icon('search', 16) ?>
        <input type="search" name="q" placeholder="Search title, requester, email…" value="<?= esc($q) ?>">
    </div>
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (\App\Models\TicketModel::STATUSES as $opt): ?>
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
                <option value="<?= esc($opt) ?>" <?= $department === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
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

<?php if ($showBulk): ?>
<form class="bulk-bar" id="bulk-form" method="post" action="/tickets/bulk" data-bulk>
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= esc(http_build_query(array_merge($baseParams, ['sort' => $sort, 'page' => $page]))) ?>">
    <label class="bulk-all"><input type="checkbox" data-bulk-all> Select all on this page</label>
    <span class="bulk-count" data-bulk-count aria-live="polite">Tick tickets to change them together</span>
    <select name="action" data-bulk-action aria-label="Bulk action">
        <option value="">Choose an action</option>
        <option value="assign">Assign to</option>
        <option value="status">Set status</option>
        <option value="priority">Set priority</option>
        <option value="close">Close</option>
    </select>
    <select name="assign_to" data-bulk-for="assign" aria-label="Assign to">
        <option value="">Pick a person</option>
        <?php foreach ($bulkStaff as $sid => $sname): ?>
            <option value="<?= (int) $sid ?>"><?= esc($sname) ?></option>
        <?php endforeach ?>
    </select>
    <select name="status" data-bulk-for="status" aria-label="New status">
        <option value="">Pick a status</option>
        <?php foreach (\App\Models\TicketModel::STATUSES as $opt): ?>
            <option value="<?= esc($opt) ?>"><?= esc($opt) ?></option>
        <?php endforeach ?>
    </select>
    <input type="text" name="note" maxlength="2000" data-bulk-for="resolved" placeholder="What fixed it (needed for Resolved)" aria-label="Resolution note">
    <select name="priority" data-bulk-for="priority" aria-label="New priority">
        <option value="">Pick a priority</option>
        <?php foreach (priorities() as $opt): ?>
            <option value="<?= esc($opt) ?>"><?= esc($opt) ?></option>
        <?php endforeach ?>
    </select>
    <button type="submit" class="btn btn-sm" data-bulk-submit>Apply to selected</button>
</form>
<?php endif ?>

<div class="card">
    <?php if (empty($tickets)): ?>
        <div class="empty-state">
            <?= icon('inbox', 40) ?>
            <div>No tickets match right now. <a href="/tickets/new">Submit a new one</a>.</div>
        </div>
    <?php else: ?>
        <table class="ticket-table">
            <thead>
                <tr>
                    <?= sortHeader('title', 'Title', $sort, $baseParams) ?>
                    <th>Department <span class="badge-ai sm"><?= icon('sparkle', 10) ?> AI</span></th>
                    <th class="col-optional">Category <span class="badge-ai sm"><?= icon('sparkle', 10) ?> AI</span></th>
                    <th class="col-optional">Requester</th>
                    <?= sortHeader('priority', 'Priority', $sort, $baseParams) ?>
                    <th>Status</th>
                    <th>SLA</th>
                    <th>Assigned</th>
                    <?= sortHeader('created', 'Created', $sort, $baseParams) ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $t): $f = $t['fields']; $m = $t['meta']; ?>
                    <tr class="ticket-row" onclick="window.location='/tickets/<?= esc($t['id'] ?? '') ?>'">
                        <td><?php if ($showBulk && ! empty($t['can_manage'])): ?><label class="bulk-pick" onclick="event.stopPropagation()"><input type="checkbox" name="ids[]" value="<?= esc($t['id'] ?? '') ?>" form="bulk-form" data-bulk-pick aria-label="Select ticket #<?= esc($t['id'] ?? '') ?>"></label><?php endif ?><a class="ticket-title" href="/tickets/<?= esc($t['id'] ?? '') ?>"><?= esc($f['Request Title'] ?? $f['Title'] ?? 'Untitled') ?></a></td>
                        <td><?= $t['department'] ? '<span class="badge-outline">' . esc($t['department']) . '</span>' : '<span class="cell-muted">—</span>' ?></td>
                        <td class="cell-muted col-optional"><?= esc($f['Category'] ?? '') ?></td>
                        <td class="cell-muted col-optional"><?= esc($f['Requester'] ?? '') ?></td>
                        <td><span class="pill <?= priorityPillClass($m['priority']) ?>"><span class="dot"></span><?= esc($m['priority']) ?></span></td>
                        <td><span class="pill <?= statusPillClass($f['Status'] ?? 'New') ?>"><?= esc($f['Status'] ?? 'New') ?></span></td>
                        <td><span class="sla-chip sla-<?= esc(str_replace('_', '-', $m['sla'] ?? 'none')) ?>" title="<?= esc(format_when($m['due_at'] ?? null)) ?>"><?= esc(\App\Libraries\Sla::label($m['sla'] ?? 'none')) ?></span></td>
                        <td class="cell-muted"><?= $m['assigned_name'] ? esc($m['assigned_name']) : '—' ?></td>
                        <td class="cell-muted"><?= esc(date('j M, g:i A', strtotime($f['CreatedAt'] ?? 'now'))) ?></td>
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

<script>
(function () {
    // filters apply on change, search after a short pause
    var form = document.querySelector('form[data-instant]');
    if (form) {
        var key = 'tickets-search-focus';
        form.querySelectorAll('select').forEach(function (s) {
            s.addEventListener('change', function () { form.submit(); });
        });
        var q = form.querySelector('input[name="q"]');
        if (q) {
            var start = q.value, timer = null;
            var go = function () {
                if (q.value.trim() === start.trim()) return;
                try { sessionStorage.setItem(key, '1'); } catch (e) {}
                form.submit();
            };
            q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(go, 600); });
            q.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { try { sessionStorage.setItem(key, '1'); } catch (err) {} }
            });
            var refocus = false;
            try { refocus = sessionStorage.getItem(key) === '1'; sessionStorage.removeItem(key); } catch (e) {}
            if (refocus) { q.focus(); var n = q.value.length; q.setSelectionRange(n, n); }
        }
    }

    // bulk bar: selection count and only the fields the action needs
    var bulk = document.querySelector('form[data-bulk]');
    if (!bulk) return;
    var picks  = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-pick]'));
    var all    = bulk.querySelector('[data-bulk-all]');
    var count  = bulk.querySelector('[data-bulk-count]');
    var action = bulk.querySelector('[data-bulk-action]');
    var status = bulk.querySelector('select[name="status"]');
    var submit = bulk.querySelector('[data-bulk-submit]');

    function selected() { return picks.filter(function (p) { return p.checked; }).length; }
    function sync() {
        var n = selected();
        count.textContent = n === 0 ? 'Tick tickets to change them together' : n + ' selected';
        all.checked = n > 0 && n === picks.length;
        all.indeterminate = n > 0 && n < picks.length;
        submit.disabled = n === 0 || !action.value;
        bulk.querySelectorAll('[data-bulk-for]').forEach(function (el) {
            var f = el.getAttribute('data-bulk-for');
            el.hidden = f === 'resolved' ? !(action.value === 'status' && status.value === 'Resolved') : f !== action.value;
        });
    }
    all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); sync(); });
    picks.forEach(function (p) { p.addEventListener('change', sync); });
    action.addEventListener('change', sync);
    status.addEventListener('change', sync);
    bulk.addEventListener('submit', function (e) {
        if (action.value === 'close' && !window.confirm('Close ' + selected() + ' ticket(s)?')) { e.preventDefault(); return; }
        submit.classList.add('is-busy');
    });
    sync();
})();
</script>

<?= $this->endSection() ?>
