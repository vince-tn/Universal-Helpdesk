<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    use App\Libraries\RoomTime;
    use App\Models\RoomBookingModel;

    $tab     = 'mine';
    $pending = count($approvals);
    $labels  = [
        RoomBookingModel::STATUS_CONFIRMED => ['Confirmed', 'pill-status-resolved'],
        RoomBookingModel::STATUS_PENDING   => ['Waiting for approval', 'pill-status-in-progress'],
        RoomBookingModel::STATUS_CANCELLED => ['Cancelled', 'pill-status-closed'],
        RoomBookingModel::STATUS_RELEASED  => ['Released, no check-in', 'pill-status-closed'],
    ];
?>

<div class="page-header">
    <div>
        <h1>My bookings</h1>
        <div class="subtitle">Check in when you arrive, cancel what you no longer need, and add bookings to your calendar.</div>
    </div>
    <a href="/rooms/book" class="btn"><?= icon('plus', 16) ?> Book a room</a>
</div>

<?php include __DIR__ . '/_flash.php' ?>
<?php include __DIR__ . '/_nav.php' ?>

<?php if ($isApprover): ?>
    <h2 class="room-h2">Waiting for your approval</h2>
    <?php if ($approvals === []): ?>
        <p class="room-foot">Nothing to approve right now.</p>
    <?php else: ?>
        <div class="room-list">
            <?php foreach ($approvals as $a): ?>
                <div class="room-item">
                    <div class="room-item-main">
                        <strong><?= esc($a['title']) ?></strong>
                        <span class="cell-muted"><?= esc($a['room_name']) ?> · <?= esc(RoomTime::date($a['starts_at'])) ?>, <?= esc(RoomTime::range($a['starts_at'], $a['ends_at'])) ?></span>
                        <span class="cell-muted">Booked by <?= esc($a['user_name'] ?? 'someone') ?> for <?= (int) $a['attendees'] ?> people</span>
                    </div>
                    <div class="row-actions">
                        <form action="/rooms/bookings/<?= (int) $a['id'] ?>/approve" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="/rooms/mine">
                            <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?> Approve</button>
                        </form>
                        <form action="/rooms/bookings/<?= (int) $a['id'] ?>/reject" method="post" onsubmit="return confirm('Decline this room request?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="/rooms/mine">
                            <button type="submit" class="btn-link is-danger">Decline</button>
                        </form>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
    <h2 class="room-h2">Your bookings</h2>
<?php endif ?>

<?php if ($bookings === []): ?>
    <div class="empty-state">
        <?= icon('building', 40) ?>
        <div>You have no upcoming bookings. <a href="/rooms">Find a free room</a>.</div>
    </div>
<?php else: ?>
    <div class="room-list">
        <?php foreach ($bookings as $b): ?>
            <?php
                $holding = in_array($b['status'], RoomBookingModel::HOLDING, true);
                $start   = RoomTime::parse($b['starts_at']);
                $canIn   = $b['status'] === RoomBookingModel::STATUS_CONFIRMED
                    && empty($b['checked_in_at'])
                    && $now >= $start->modify('-15 minutes')
                    && $now <= $start->modify('+15 minutes');
                [$label, $pill] = $labels[$b['status']] ?? [$b['status'], 'pill-status-closed'];
            ?>
            <div class="room-item <?= $holding ? '' : 'is-off' ?>">
                <div class="room-item-main">
                    <strong><?= esc($b['title']) ?></strong>
                    <span class="cell-muted"><?= esc($b['room_name']) ?><?= ! empty($b['room_location']) ? ', ' . esc($b['room_location']) : '' ?></span>
                    <span class="cell-muted"><?= esc(RoomTime::weekday($b['starts_at'])) ?>, <?= esc(RoomTime::range($b['starts_at'], $b['ends_at'])) ?></span>
                    <span>
                        <span class="pill <?= $pill ?>"><?= esc($label) ?></span>
                        <?php if (! empty($b['checked_in_at'])): ?>
                            <span class="pill pill-status-resolved">Checked in <?= esc(RoomTime::time($b['checked_in_at'])) ?></span>
                        <?php endif ?>
                        <?php if (! empty($b['recurrence_group'])): ?>
                            <span class="badge-outline">Weekly</span>
                        <?php endif ?>
                    </span>
                </div>
                <div class="row-actions">
                    <?php if ($canIn): ?>
                        <form action="/rooms/bookings/<?= (int) $b['id'] ?>/checkin" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="/rooms/mine">
                            <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?> Check in</button>
                        </form>
                    <?php endif ?>
                    <?php if ($holding): ?>
                        <a class="btn btn-secondary btn-sm" href="/rooms/bookings/<?= (int) $b['id'] ?>/ics"><?= icon('download', 14) ?> Calendar</a>
                        <form action="/rooms/bookings/<?= (int) $b['id'] ?>/cancel" method="post" onsubmit="return confirm('Cancel this booking?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="/rooms/mine">
                            <button type="submit" class="btn-link is-danger">Cancel</button>
                        </form>
                        <?php if (! empty($b['recurrence_group'])): ?>
                            <form action="/rooms/bookings/<?= (int) $b['id'] ?>/cancel" method="post" onsubmit="return confirm('Cancel this and every later week in the series?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="series" value="1">
                                <input type="hidden" name="return_to" value="/rooms/mine">
                                <button type="submit" class="btn-link is-danger">Cancel this and later weeks</button>
                            </form>
                        <?php endif ?>
                    <?php endif ?>
                    <a class="btn-link" href="/tickets/new?room=<?= (int) $b['room_id'] ?>">Report a room issue</a>
                </div>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>

<p class="room-foot">Check-in opens 15 minutes before your start and closes 15 minutes after. If nobody checks in, the room is released for others.</p>

<?= $this->endSection() ?>
