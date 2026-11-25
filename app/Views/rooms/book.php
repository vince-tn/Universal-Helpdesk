<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    use App\Libraries\RoomTime;
    use App\Models\RoomModel;

    $roomId = (int) old('room_id', (string) $pick['room_id']);
    $date   = (string) old('date', $pick['date']);
    $start  = (string) old('start', $pick['start']);
    $end    = (string) old('end', $pick['end']);
    $weeks  = (int) old('repeat_weeks', '1');
?>

<div class="page-header">
    <div>
        <h1>Book a room</h1>
        <div class="subtitle">Pick a room and a time. If it is free, it is yours straight away.</div>
    </div>
    <a href="/rooms?date=<?= esc($date) ?>" class="btn btn-secondary"><?= icon('chevron-left', 16) ?> Back to rooms</a>
</div>

<?php include __DIR__ . '/_flash.php' ?>

<div class="form-card room-form">
    <form action="/rooms/book" method="post">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="room_id">Room</label>
            <select id="room_id" name="room_id" required>
                <option value="">Choose a room</option>
                <?php foreach ($rooms as $r): ?>
                    <?php $gear = RoomModel::equipmentList($r['equipment'] ?? ''); ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $roomId === (int) $r['id'] ? 'selected' : '' ?>>
                        <?= esc($r['name']) ?> (fits <?= (int) $r['capacity'] ?><?= $gear !== [] ? ', ' . esc(implode(', ', $gear)) : '' ?>)<?= (int) $r['needs_approval'] === 1 ? ', needs approval' : '' ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="form-group">
            <label for="title">What is it for?</label>
            <input type="text" id="title" name="title" maxlength="150" required value="<?= esc(old('title', '')) ?>" placeholder="e.g. Weekly team huddle">
        </div>

        <div class="form-grid">
            <div class="form-group span-2">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" required value="<?= esc($date) ?>" min="<?= esc(RoomTime::now()->format('Y-m-d')) ?>">
            </div>

            <div class="form-group">
                <label for="start">From</label>
                <select id="start" name="start" required>
                    <option value="">Start time</option>
                    <?php foreach ($slots as $s): ?>
                        <option value="<?= esc($s) ?>" <?= $start === $s ? 'selected' : '' ?>><?= esc(RoomTime::slotLabel($s)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="form-group">
                <label for="end">To</label>
                <select id="end" name="end" required>
                    <option value="">End time</option>
                    <?php foreach ($endTimes as $e): ?>
                        <option value="<?= esc($e) ?>" <?= $end === $e ? 'selected' : '' ?>><?= esc(RoomTime::slotLabel($e)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="form-group">
                <label for="attendees">How many people?</label>
                <input type="text" id="attendees" name="attendees" inputmode="numeric" pattern="[0-9]*" maxlength="3" required value="<?= esc(old('attendees', '2')) ?>">
            </div>

            <div class="form-group">
                <label for="repeat_weeks">Repeat</label>
                <select id="repeat_weeks" name="repeat_weeks">
                    <option value="1">Just this once</option>
                    <?php for ($w = 2; $w <= $maxWeeks; $w++): ?>
                        <option value="<?= $w ?>" <?= $weeks === $w ? 'selected' : '' ?>>Every week for <?= $w ?> weeks</option>
                    <?php endfor ?>
                </select>
            </div>
        </div>

        <div class="note-strip">
            <?= icon('clock', 15) ?>
            <span>
                Times are Philippine time (PHT). Bookings over 4 hours, or in rooms marked "needs approval", wait for a supervisor to approve them.
                For weekly bookings, any week where the room is taken is skipped and we tell you which dates.
            </span>
        </div>

        <button type="submit" class="btn"><?= icon('check', 16) ?> Book it</button>
    </form>
</div>

<?= $this->endSection() ?>
