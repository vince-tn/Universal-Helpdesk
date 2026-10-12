<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    function reportStatusColor(string $status): string {
        return match ($status) {
            'New' => 'var(--status-new-text)',
            'In Progress' => 'var(--status-progress-text)',
            'Resolved' => 'var(--status-resolved-text)',
            'Closed' => 'var(--status-closed-text)',
            default => 'var(--status-new-text)',
        };
    }
    function reportPriorityColor(string $priority): string {
        return match ($priority) {
            'Low' => 'var(--priority-low-text)',
            'High' => 'var(--priority-high-text)',
            'Urgent' => 'var(--priority-urgent-text)',
            default => 'var(--priority-medium-text)',
        };
    }

    function barRow(string $label, int $value, int $max, string $color = 'var(--accent-bright)'): string {
        $pct = $max > 0 ? max(2, round($value / $max * 100)) : 0;
        return '<div class="barlist-row">'
            . '<div class="barlist-label">' . esc($label) . '</div>'
            . '<div class="barlist-track"><div class="barlist-fill" style="width:' . $pct . '%;background:' . $color . '"></div></div>'
            . '<div class="barlist-value">' . $value . '</div>'
            . '</div>';
    }

    $maxDept     = max(1, ...array_values($departmentCounts));
    $maxCategory = empty($topCategories) ? 1 : max(1, ...array_values($topCategories));
    $maxStatus   = max(1, ...array_values($statusCounts));
    $maxPriority = max(1, ...array_values($priorityCounts));
    $maxDay      = max(1, ...array_values($volumeByDay));
    $maxWorkload = empty($agentWorkload) ? 1 : max(1, ...array_column($agentWorkload, 'open'));
?>

<div class="page-header">
    <div>
        <h1><?= icon('trend', 24) ?> Reports &amp; Analytics</h1>
        <div class="subtitle">Cross-department ticket volume, workload, and health — as of <?= esc(date('M j, Y g:i A')) ?>.</div>
    </div>
    <div class="export-group">
        <span class="export-label"><?= icon('download', 14) ?> Export raw data</span>
        <a href="/tickets/export?format=csv">CSV</a>
        <a href="/tickets/export?format=xls">Excel</a>
        <a href="/tickets/export?format=json">JSON</a>
    </div>
</div>

<form class="toolbar" method="get" action="/reports">
    <select name="department">
        <option value="">All departments</option>
        <?php foreach (departments() as $opt): ?>
            <option value="<?= esc($opt) ?>" <?= $department === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
        <?php endforeach ?>
    </select>
    <label class="toolbar-daterange">
        From
        <input type="date" name="date_from" value="<?= esc($dateFrom) ?>">
    </label>
    <label class="toolbar-daterange">
        To
        <input type="date" name="date_to" value="<?= esc($dateTo) ?>">
    </label>
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
    <?php if ($department || $dateFrom || $dateTo): ?>
        <a href="/reports" class="toolbar-clear">Clear filters</a>
    <?php endif ?>
</form>

<div class="kpi-row">
    <div class="stat-card">
        <div class="value"><?= $total ?></div>
        <div class="label">Total Tickets</div>
    </div>
    <div class="stat-card accent-new">
        <div class="value"><?= $openCount ?></div>
        <div class="label">Open</div>
    </div>
    <div class="stat-card accent-done">
        <div class="value"><?= $resolvedCount ?></div>
        <div class="label">Resolved / Closed</div>
    </div>
    <div class="stat-card <?= $overdueCount > 0 ? 'accent-urgent' : '' ?>">
        <div class="value"><?= $overdueCount ?></div>
        <div class="label">Overdue</div>
    </div>
    <div class="stat-card">
        <div class="value"><?= $unassignedOpen ?></div>
        <div class="label">Unassigned &amp; Open</div>
    </div>
</div>

<div class="detail-layout">
    <div>
        <div class="side-panel">
            <h3><?= icon('department', 15) ?> Tickets by Department</h3>
            <div class="barlist">
                <?= implode('', array_map(fn ($dept, $count) => barRow($dept, $count, $maxDept), array_keys($departmentCounts), $departmentCounts)) ?>
            </div>
        </div>

        <div class="side-panel">
            <h3><?= icon('inbox', 15) ?> Top Categories</h3>
            <?php if (empty($topCategories)): ?>
                <div class="thread-empty">No categorized tickets yet.</div>
            <?php else: ?>
                <div class="barlist">
                    <?= implode('', array_map(fn ($cat, $count) => barRow($cat, $count, $maxCategory), array_keys($topCategories), $topCategories)) ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <div>
        <div class="side-panel">
            <h3><?= icon('flag', 15) ?> By Status</h3>
            <div class="barlist">
                <?= implode('', array_map(fn ($s, $count) => barRow($s, $count, $maxStatus, reportStatusColor($s)), array_keys($statusCounts), $statusCounts)) ?>
            </div>
        </div>

        <div class="side-panel">
            <h3><?= icon('flag', 15) ?> By Priority</h3>
            <div class="barlist">
                <?= implode('', array_map(fn ($p, $count) => barRow($p, $count, $maxPriority, reportPriorityColor($p)), array_keys($priorityCounts), $priorityCounts)) ?>
            </div>
        </div>
    </div>
</div>

<div class="side-panel">
    <h3>
        <?= icon('clock', 15) ?>
        Ticket Volume —
        <?= ($dateFrom || $dateTo)
            ? esc(date('M j, Y', strtotime($volumeRangeStart))) . ' – ' . esc(date('M j, Y', strtotime($volumeRangeEnd)))
            : 'Last 14 Days' ?>
    </h3>
    <?php if ($volumeRangeTruncated): ?>
        <div class="field-hint" style="margin:-8px 0 14px;">Selected range is long — showing the most recent 60 days of it.</div>
    <?php endif ?>
    <div class="daychart-scroll">
        <div class="daychart">
            <?php foreach ($volumeByDay as $day => $count): $h = max(4, round($count / $maxDay * 100)); ?>
                <div class="daychart-col" title="<?= esc(date('M j', strtotime($day))) ?>: <?= $count ?> ticket<?= $count === 1 ? '' : 's' ?>">
                    <?php if ($count === $maxDay && $count > 0): ?><div class="daychart-cap"><?= $count ?></div><?php endif ?>
                    <div class="daychart-bar" style="height:<?= $h ?>%"></div>
                    <div class="daychart-axis"><?= esc(date('n/j', strtotime($day))) ?></div>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</div>

<div class="side-panel">
    <h3><?= icon('users', 15) ?> Staff Workload (open tickets)</h3>
    <?php if (empty($agentWorkload)): ?>
        <div class="thread-empty">No tickets are assigned to anyone yet.</div>
    <?php else: ?>
        <div class="barlist">
            <?php foreach ($agentWorkload as $a): ?>
                <div class="barlist-row">
                    <div class="barlist-label"><?= esc($a['name']) ?> <span class="badge-outline"><?= esc($a['department']) ?></span></div>
                    <div class="barlist-track"><div class="barlist-fill" style="width:<?= max(2, round($a['open'] / $maxWorkload * 100)) ?>%"></div></div>
                    <div class="barlist-value"><?= $a['open'] ?> / <?= $a['total'] ?></div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>

<?= $this->endSection() ?>
