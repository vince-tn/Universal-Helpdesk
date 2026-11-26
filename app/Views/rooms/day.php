<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    use App\Libraries\RoomTime;
    use App\Models\RoomModel;

    $tab     = 'day';
    $prev    = $day->modify('-1 day')->format('Y-m-d');
    $next    = $day->modify('+1 day')->format('Y-m-d');
    $isToday = $day->format('Y-m-d') === $now->format('Y-m-d');

    $headers = [];
    foreach ($columns as $col) {
        $r     = $col['room'];
        $gear  = RoomModel::equipmentList($r['equipment'] ?? '');
        $html  = '<div class="rg-room">';
        $html .= '<span class="rg-room-name">' . esc($r['name']) . '</span>';
        $html .= '<span class="rg-room-meta">Fits ' . (int) $r['capacity'] . ($gear !== [] ? ' · ' . esc(implode(', ', $gear)) : '') . '</span>';
        if ((int) $r['needs_approval'] === 1) {
            $html .= '<span class="rg-room-tag">Needs approval</span>';
        }
        $html .= '<a class="rg-room-issue" href="/tickets/new?room=' . (int) $r['id'] . '">Report a room issue</a>';
        $html .= '</div>';
        $headers[] = $html;
    }
?>

<div class="page-header">
    <div>
        <h1>Room bookings</h1>
        <div class="subtitle">See which rooms are free and book one in seconds. Tap any open slot to start.</div>
    </div>
    <a href="/rooms/book?date=<?= esc($day->format('Y-m-d')) ?>" class="btn"><?= icon('plus', 16) ?> Book a room</a>
</div>

<?php include __DIR__ . '/_flash.php' ?>
<?php include __DIR__ . '/_nav.php' ?>

<div class="room-toolbar">
    <div class="room-dates">
        <a class="btn btn-secondary btn-sm" href="/rooms?date=<?= esc($prev) ?>" aria-label="Previous day"><?= icon('chevron-left', 14) ?></a>
        <strong class="room-day-label"><?= esc(RoomTime::weekday($day)) ?><?= $isToday ? ' · Today' : '' ?></strong>
        <a class="btn btn-secondary btn-sm room-next" href="/rooms?date=<?= esc($next) ?>" aria-label="Next day"><?= icon('chevron-left', 14) ?></a>
        <?php if (! $isToday): ?>
            <a class="btn-link" href="/rooms">Back to today</a>
        <?php endif ?>
    </div>
    <form method="get" action="/rooms" class="room-jump">
        <label for="jump-date" class="sr-only">Go to date</label>
        <input type="date" id="jump-date" name="date" value="<?= esc($day->format('Y-m-d')) ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
    </form>
</div>

<?php if ($rooms === []): ?>
    <div class="empty-state">
        <?= icon('building', 40) ?>
        <div>No rooms have been set up yet.<?= $isSuper ? ' <a href="/rooms/manage">Add the first room</a>.' : ' Ask a super admin to add some.' ?></div>
    </div>
<?php else: ?>
    <?php include __DIR__ . '/_grid.php' ?>
    <p class="room-foot">All times are Philippine time (PHT). Check in within 15 minutes of your start or the room is released for others.</p>
<?php endif ?>

<?= $this->endSection() ?>
