<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    $statusColor = static fn (string $status): string => match ($status) {
        'New'                => 'var(--status-new-text)',
        'In Progress'        => 'var(--status-progress-text)',
        'Awaiting Requester' => 'var(--plum)',
        'On Hold'            => 'var(--ink-3)',
        'Pending Approval'   => 'var(--amber)',
        'Resolved'           => 'var(--status-resolved-text)',
        'Reopened'           => 'var(--vermilion)',
        'Closed'             => 'var(--status-closed-text)',
        default              => 'var(--ink-3)',
    };
    $priorityColor = static fn (string $priority): string => match ($priority) {
        'Low'    => 'var(--priority-low-text)',
        'High'   => 'var(--priority-high-text)',
        'Urgent' => 'var(--priority-urgent-text)',
        default  => 'var(--priority-medium-text)',
    };
    $barRow = static function (string $label, int $value, int $max, string $color = 'var(--accent-bright)'): string {
        $pct = $max > 0 && $value > 0 ? max(2, round($value / $max * 100)) : 0;

        return '<div class="barlist-row">'
            . '<div class="barlist-label" title="' . esc($label) . '">' . esc($label) . '</div>'
            . '<div class="barlist-track"><div class="barlist-fill" style="width:' . $pct . '%;background:' . $color . '"></div></div>'
            . '<div class="barlist-value">' . $value . '</div>'
            . '</div>';
    };
    $bars = static function (array $counts, ?callable $color = null) use ($barRow): string {
        $max = $counts === [] ? 1 : max(1, ...array_values($counts));
        $out = '';
        foreach ($counts as $label => $count) {
            $out .= $color ? $barRow((string) $label, (int) $count, $max, $color((string) $label)) : $barRow((string) $label, (int) $count, $max);
        }

        return $out;
    };
    $pct      = static fn (?float $p): string => $p === null ? 'No data yet' : rtrim(rtrim(number_format($p, 1), '0'), '.') . '%';
    $day      = static fn (string $ymd): string => date('j M Y', strtotime($ymd));
    $plural   = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
    $rangeTxt = $day($dateFrom) . ' to ' . $day($dateTo);
    $maxDay   = max(1, ...array_values($volumeByDay ?: [0]));
    $slaNote  = 'SLA data starts once SLAs are on';
?>

<div class="reports-page">

<div class="page-header">
    <div>
        <h1><?= icon('trend', 24) ?> Reports &amp; Analytics</h1>
        <div class="subtitle">Tickets created <?= esc($rangeTxt) ?><?= $department !== '' ? ' in ' . esc($department) : '' ?>. Updated <?= esc(date('j M Y g:i A')) ?>.</div>
    </div>
    <?php if ($canExport): ?>
        <div class="report-exports">
            <a class="btn btn-secondary btn-sm" href="/reports/export?<?= esc($queryString) ?>"><?= icon('download', 14) ?> Summary CSV</a>
            <div class="export-group">
                <span class="export-label">Raw tickets</span>
                <a href="/tickets/export?format=csv">CSV</a>
                <a href="/tickets/export?format=xls">Excel</a>
                <a href="/tickets/export?format=json">JSON</a>
            </div>
        </div>
    <?php endif ?>
</div>

<form class="toolbar report-filters" method="get" action="/reports">
    <div class="report-range" role="radiogroup" aria-label="Date range">
        <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'custom' => 'Custom'] as $value => $label): ?>
            <label class="report-range-opt">
                <input type="radio" name="range" value="<?= $value ?>" <?= $range === (string) $value ? 'checked' : '' ?> data-range>
                <span><?= $label ?></span>
            </label>
        <?php endforeach ?>
    </div>
    <div class="report-custom" data-custom <?= $range === 'custom' ? '' : 'hidden' ?>>
        <label class="report-field">From <input type="date" name="date_from" value="<?= esc($dateFrom) ?>"></label>
        <label class="report-field">To <input type="date" name="date_to" value="<?= esc($dateTo) ?>"></label>
    </div>
    <label class="report-field">
        Department
        <select name="department">
            <option value="">All departments</option>
            <?php foreach (departments() as $opt): ?>
                <option value="<?= esc($opt) ?>" <?= $department === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
    <?php if ($range !== '30' || $department !== ''): ?>
        <a href="/reports" class="report-reset">Reset</a>
    <?php endif ?>
</form>

<h2 class="report-section-title">Volume</h2>
<div class="report-kpis">
    <div class="stat-card">
        <div class="value"><?= $total ?></div>
        <div class="label">Tickets created</div>
    </div>
    <div class="stat-card accent-new">
        <div class="value"><?= $openCount ?></div>
        <div class="label">Still open</div>
    </div>
    <div class="stat-card accent-done">
        <div class="value"><?= $resolvedCount ?></div>
        <div class="label">Resolved or closed</div>
    </div>
    <div class="stat-card <?= $overdueCount > 0 ? 'accent-urgent' : '' ?>">
        <div class="value"><?= $overdueCount ?></div>
        <div class="label">Open and overdue</div>
    </div>
</div>

<h2 class="report-section-title">Speed</h2>
<div class="report-kpis">
    <div class="stat-card">
        <div class="value is-text"><?= esc(format_duration($firstMedian)) ?></div>
        <div class="label">First response, median</div>
        <div class="sub">Across <?= $plural($firstCount, 'answered ticket') ?></div>
    </div>
    <div class="stat-card">
        <div class="value is-text"><?= esc(format_duration($firstAverage)) ?></div>
        <div class="label">First response, average</div>
    </div>
    <div class="stat-card">
        <div class="value is-text"><?= esc(format_duration($resAverage)) ?></div>
        <div class="label">Average resolution time</div>
        <div class="sub">Across <?= $plural($resCount, 'resolved ticket') ?></div>
    </div>
    <div class="stat-card">
        <div class="value is-text"><?= $oldestOpen === null ? 'None open' : esc($plural($oldestOpen, 'day')) ?></div>
        <div class="label">Oldest open ticket</div>
    </div>
</div>

<h2 class="report-section-title">Quality</h2>
<div class="report-kpis">
    <div class="stat-card accent-done">
        <?php if ($slaOn): ?>
            <div class="value"><?= esc($pct($slaMetPct)) ?></div>
            <div class="label">Resolution SLA met</div>
            <div class="sub"><?= $slaMet ?> met, <?= $slaPending ?> still within target<?= $respSlaPct !== null ? '. Response target met ' . esc($pct($respSlaPct)) : '' ?></div>
        <?php else: ?>
            <div class="value is-note"><?= $slaNote ?></div>
            <div class="label">Resolution SLA met</div>
        <?php endif ?>
    </div>
    <div class="stat-card <?= $slaOn && $slaBreached > 0 ? 'accent-urgent' : '' ?>">
        <?php if ($slaOn): ?>
            <div class="value"><?= esc($pct($slaBreachedPct)) ?></div>
            <div class="label">Resolution SLA breached</div>
            <div class="sub"><?= $plural($slaBreached, 'ticket') ?> past target</div>
        <?php else: ?>
            <div class="value is-note"><?= $slaNote ?></div>
            <div class="label">Resolution SLA breached</div>
        <?php endif ?>
    </div>
    <div class="stat-card">
        <div class="value"><?= esc($pct($reopenRate)) ?></div>
        <div class="label">Reopen rate</div>
        <div class="sub"><?= $plural($reopenCount, 'ticket') ?><?= $reopenTracked ? ' reopened at least once' : ' currently reopened' ?></div>
    </div>
    <div class="stat-card">
        <?php if ($csatOn && $csatAverage !== null): ?>
            <div class="value"><?= esc(number_format($csatAverage, 1)) ?><span class="value-unit"> / 5</span></div>
            <div class="label">Customer satisfaction</div>
            <div class="sub">From <?= $plural($csatCount, 'response') ?></div>
        <?php else: ?>
            <div class="value is-note"><?= $csatOn ? 'No ratings in this range yet' : 'Ratings start once feedback is on' ?></div>
            <div class="label">Customer satisfaction</div>
        <?php endif ?>
    </div>
</div>

<h2 class="report-section-title">Open backlog by age</h2>
<div class="report-kpis">
    <?php foreach ($ageing as $bucket => $count): ?>
        <div class="stat-card <?= $bucket === '6+ days' && $count > 0 ? 'accent-urgent' : ($bucket === '0 to 2 days' ? 'accent-new' : 'accent-progress') ?>">
            <div class="value"><?= $count ?></div>
            <div class="label">Open <?= esc($bucket) ?></div>
        </div>
    <?php endforeach ?>
    <div class="stat-card">
        <div class="value"><?= $unassignedOpen ?></div>
        <div class="label">Open with no owner</div>
    </div>
</div>

<div class="detail-layout">
    <div>
        <div class="side-panel">
            <h3><?= icon('flag', 15) ?> By status</h3>
            <div class="barlist"><?= $bars(array_filter($statusCounts, static fn ($c, $s) => $c > 0 || in_array($s, ['New', 'In Progress', 'Resolved', 'Closed'], true), ARRAY_FILTER_USE_BOTH), $statusColor) ?></div>
        </div>
        <div class="side-panel">
            <h3><?= icon('inbox', 15) ?> Top categories</h3>
            <?php if (empty($topCategories)): ?>
                <div class="thread-empty">No tickets in this range.</div>
            <?php else: ?>
                <div class="barlist"><?= $bars($topCategories) ?></div>
            <?php endif ?>
        </div>
    </div>
    <div>
        <div class="side-panel">
            <h3><?= icon('flag', 15) ?> By priority</h3>
            <div class="barlist"><?= $bars($priorityCounts, $priorityColor) ?></div>
        </div>
    </div>
</div>

<div class="side-panel">
    <h3><?= icon('clock', 15) ?> Tickets created per day</h3>
    <?php if ($volumeTrimmed): ?>
        <div class="field-hint report-hint">This range is long, so the chart shows its most recent 92 days. Every other figure covers the full range.</div>
    <?php endif ?>
    <div class="daychart-scroll">
        <div class="daychart">
            <?php foreach ($volumeByDay as $d => $count): $h = max(4, round($count / $maxDay * 100)); ?>
                <div class="daychart-col" title="<?= esc($day($d)) ?>: <?= esc($plural($count, 'ticket')) ?>">
                    <?php if ($count === $maxDay && $count > 0): ?><div class="daychart-cap"><?= $count ?></div><?php endif ?>
                    <div class="daychart-bar" style="height:<?= $h ?>%"></div>
                    <div class="daychart-axis"><?= esc(date('j M', strtotime($d))) ?></div>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</div>

<?php foreach ($breakdowns as $key => $b): ?>
    <div class="side-panel">
        <h3><?= icon($key === 'agent' ? 'users' : ($key === 'company' ? 'building' : 'department'), 15) ?> <?= esc($b['title']) ?></h3>
        <?php if (empty($b['rows'])): ?>
            <div class="thread-empty">No tickets in this range.</div>
        <?php else: ?>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col" class="num">Tickets</th>
                            <th scope="col" class="num">Open</th>
                            <th scope="col" class="num">Resolved</th>
                            <th scope="col" class="num">Avg first response</th>
                            <th scope="col" class="num">Avg resolution</th>
                            <th scope="col" class="num">SLA met</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($b['rows'], 0, $tableTop) as $r): ?>
                            <tr>
                                <th scope="row"><?= esc($r['name']) ?></th>
                                <td class="num"><?= $r['tickets'] ?></td>
                                <td class="num"><?= $r['open'] ?></td>
                                <td class="num"><?= $r['resolved'] ?></td>
                                <td class="num"><?= esc($r['avgFirst'] === null ? 'None' : format_duration($r['avgFirst'])) ?></td>
                                <td class="num"><?= esc($r['avgRes'] === null ? 'None' : format_duration($r['avgRes'])) ?></td>
                                <td class="num"><?= $r['slaMet'] === null ? 'None' : esc($pct($r['slaMet'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($b['rows']) > $tableTop): ?>
                <div class="field-hint">Showing the top <?= $tableTop ?> of <?= count($b['rows']) ?>. The summary CSV lists them all.</div>
            <?php endif ?>
        <?php endif ?>
    </div>
<?php endforeach ?>

</div>

<script>
(function () {
    var custom = document.querySelector('[data-custom]');
    document.querySelectorAll('[data-range]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (custom) { custom.hidden = r.value !== 'custom' || !r.checked; }
        });
    });
})();
</script>

<?= $this->endSection() ?>
