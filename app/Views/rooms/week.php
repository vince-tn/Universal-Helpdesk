<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    use App\Libraries\RoomTime;
    use App\Models\RoomModel;

    $tab    = 'week';
    $roomQ  = $room !== null ? '&amp;room=' . (int) $room['id'] : '';
    $prev   = $monday->modify('-7 days')->format('Y-m-d');
    $next   = $monday->modify('+7 days')->format('Y-m-d');
    $sunday = $monday->modify('+6 days');
    $today  = $now->format('Y-m-d');

    $headers = [];
    foreach ($columns as $col) {
        $d         = $col['day'];
        $headers[] = '<a class="rg-day' . ($d->format('Y-m-d') === $today ? ' is-today' : '') . '" href="/rooms?date=' . esc($d->format('Y-m-d')) . '">'
            . '<span class="rg-room-name">' . esc($d->format('D')) . '</span>'
            . '<span class="rg-room-meta">' . esc(RoomTime::date($d)) . '</span></a>';
    }
?>

<div class="page-header">
    <div>
        <h1>Room bookings</h1>
        <div class="subtitle">One room, the whole week. Tap any open slot to book it.</div>
    </div>
    <a href="/rooms/book?date=<?= esc($day->format('Y-m-d')) ?><?= $room !== null ? '&amp;room=' . (int) $room['id'] : '' ?>" class="btn"><?= icon('plus', 16) ?> Book a room</a>
</div>

<?php include __DIR__ . '/_flash.php' ?>
<?php include __DIR__ . '/_nav.php' ?>

<?php if ($room === null): ?>
    <div class="empty-state">
        <?= icon('building', 40) ?>
        <div>No rooms have been set up yet.<?= $isSuper ? ' <a href="/rooms/manage">Add the first room</a>.' : ' Ask a super admin to add some.' ?></div>
    </div>
<?php else: ?>
    <div class="room-toolbar">
        <div class="room-dates">
            <a class="btn btn-secondary btn-sm" href="/rooms/week?date=<?= esc($prev) ?><?= $roomQ ?>" aria-label="Previous week"><?= icon('chevron-left', 14) ?></a>
            <strong class="room-day-label"><?= esc(RoomTime::date($monday)) ?> to <?= esc(RoomTime::date($sunday)) ?></strong>
            <a class="btn btn-secondary btn-sm room-next" href="/rooms/week?date=<?= esc($next) ?><?= $roomQ ?>" aria-label="Next week"><?= icon('chevron-left', 14) ?></a>
        </div>
        <form method="get" action="/rooms/week" class="room-jump">
            <label for="week-room" class="sr-only">Room</label>
            <select id="week-room" name="room" onchange="this.form.submit()">
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $room['id'] ? 'selected' : '' ?>><?= esc($r['name']) ?></option>
                <?php endforeach ?>
            </select>
            <label for="week-date" class="sr-only">Week of</label>
            <input type="date" id="week-date" name="date" value="<?= esc($day->format('Y-m-d')) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Go</button>
        </form>
    </div>

    <?php $gear = RoomModel::equipmentList($room['equipment'] ?? ''); ?>
    <div class="note-strip">
        <?= icon('building', 15) ?>
        <span>
            <strong><?= esc($room['name']) ?></strong><?= ! empty($room['location']) ? ', ' . esc($room['location']) : '' ?>.
            Fits <?= (int) $room['capacity'] ?><?= $gear !== [] ? '. Has ' . esc(implode(', ', $gear)) : '' ?>.
            <?= (int) $room['needs_approval'] === 1 ? 'Bookings here need a supervisor to approve them.' : '' ?>
            <a href="/tickets/new?room=<?= (int) $room['id'] ?>">Report a room issue</a>
        </span>
    </div>

    <?php include __DIR__ . '/_grid.php' ?>
    <p class="room-foot">All times are Philippine time (PHT). Check in within 15 minutes of your start or the room is released for others.</p>
<?php endif ?>

<?= $this->endSection() ?>
