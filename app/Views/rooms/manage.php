<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    $tab     = 'manage';
    $isSuper = true;
?>

<div class="page-header">
    <div>
        <h1>Manage rooms</h1>
        <div class="subtitle">Add rooms people can book, keep their details current, and hide rooms that are out of use.</div>
    </div>
</div>

<?php include __DIR__ . '/_flash.php' ?>
<?php include __DIR__ . '/_nav.php' ?>

<div class="form-card room-form room-manage-add">
    <h2 class="room-h2">Add a room</h2>
    <form action="/rooms/manage" method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group">
                <label for="new-name">Name</label>
                <input type="text" id="new-name" name="name" maxlength="80" required value="<?= esc(old('name', '')) ?>" placeholder="e.g. Huddle Room">
            </div>
            <div class="form-group">
                <label for="new-location">Location</label>
                <input type="text" id="new-location" name="location" maxlength="150" value="<?= esc(old('location', '')) ?>" placeholder="e.g. 3rd floor, east wing">
            </div>
            <div class="form-group">
                <label for="new-capacity">Fits how many people</label>
                <input type="text" id="new-capacity" name="capacity" inputmode="numeric" pattern="[0-9]*" maxlength="3" required value="<?= esc(old('capacity', '6')) ?>">
            </div>
            <div class="form-group">
                <label for="new-equipment">Equipment</label>
                <input type="text" id="new-equipment" name="equipment" maxlength="255" value="<?= esc(old('equipment', '')) ?>" placeholder="TV, conference phone, whiteboard">
            </div>
            <div class="form-group span-2 checkbox-row">
                <input type="checkbox" id="new-approval" name="needs_approval" value="1" <?= old('needs_approval') ? 'checked' : '' ?>>
                <label for="new-approval">Bookings need a supervisor to approve them</label>
            </div>
        </div>
        <button type="submit" class="btn btn-sm"><?= icon('plus', 15) ?> Add room</button>
    </form>
</div>

<?php if ($rooms === []): ?>
    <div class="empty-state">
        <?= icon('building', 40) ?>
        <div>No rooms yet. Add the first one above.</div>
    </div>
<?php endif ?>

<div class="room-list">
    <?php foreach ($rooms as $r): ?>
        <?php $id = (int) $r['id']; $on = (int) $r['is_active'] === 1; ?>
        <section class="room-item room-edit <?= $on ? '' : 'is-off' ?>">
            <form action="/rooms/manage/<?= $id ?>" method="post" class="room-edit-form">
                <?= csrf_field() ?>
                <div class="room-edit-grid">
                    <div>
                        <label for="n-<?= $id ?>">Name</label>
                        <input type="text" id="n-<?= $id ?>" name="name" maxlength="80" required value="<?= esc($r['name']) ?>">
                    </div>
                    <div>
                        <label for="l-<?= $id ?>">Location</label>
                        <input type="text" id="l-<?= $id ?>" name="location" maxlength="150" value="<?= esc($r['location'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="c-<?= $id ?>">Fits</label>
                        <input type="text" id="c-<?= $id ?>" name="capacity" inputmode="numeric" pattern="[0-9]*" maxlength="3" required value="<?= (int) $r['capacity'] ?>">
                    </div>
                    <div>
                        <label for="e-<?= $id ?>">Equipment</label>
                        <input type="text" id="e-<?= $id ?>" name="equipment" maxlength="255" value="<?= esc($r['equipment'] ?? '') ?>">
                    </div>
                </div>
                <div class="room-edit-foot">
                    <div class="checkbox-row">
                        <input type="checkbox" id="a-<?= $id ?>" name="needs_approval" value="1" <?= (int) $r['needs_approval'] === 1 ? 'checked' : '' ?>>
                        <label for="a-<?= $id ?>">Needs approval</label>
                    </div>
                    <span class="pill <?= $on ? 'pill-status-resolved' : 'pill-status-closed' ?>"><?= $on ? 'Bookable' : 'Hidden' ?></span>
                    <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?> Save</button>
                </div>
            </form>
            <div class="row-actions">
                <form action="/rooms/manage/<?= $id ?>/toggle" method="post"
                      <?= $on ? 'onsubmit="return confirm(\'Hide ' . esc($r['name'], 'js') . ' from booking? Existing bookings stay.\');"' : '' ?>>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-link <?= $on ? 'is-danger' : '' ?>"><?= $on ? 'Deactivate' : 'Make bookable again' ?></button>
                </form>
                <a class="btn-link" href="/rooms/week?room=<?= $id ?>">See this week</a>
            </div>
        </section>
    <?php endforeach ?>
</div>

<?= $this->endSection() ?>
