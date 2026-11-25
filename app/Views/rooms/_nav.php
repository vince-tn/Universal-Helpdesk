<?php
    // tabs shared by the room pages
    $tab = $tab ?? 'day';
?>
<nav class="room-tabs" aria-label="Room booking pages">
    <a href="/rooms<?= isset($day) ? '?date=' . esc($day->format('Y-m-d')) : '' ?>" class="<?= $tab === 'day' ? 'is-on' : '' ?>">Day</a>
    <a href="/rooms/week<?= isset($day) ? '?date=' . esc($day->format('Y-m-d')) : '' ?>" class="<?= $tab === 'week' ? 'is-on' : '' ?>">Week</a>
    <a href="/rooms/mine" class="<?= $tab === 'mine' ? 'is-on' : '' ?>">
        My bookings<?php if (! empty($pending)): ?> <span class="nav-badge"><?= (int) $pending ?></span><?php endif ?>
    </a>
    <?php if (! empty($isSuper)): ?>
        <a href="/rooms/manage" class="<?= $tab === 'manage' ? 'is-on' : '' ?>">Manage rooms</a>
    <?php endif ?>
</nav>
