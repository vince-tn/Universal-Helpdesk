<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>Notifications</h1>
        <div class="subtitle">Everywhere you've been mentioned.</div>
    </div>
</div>

<?php if (empty($notifications)): ?>
    <div class="empty-state">
        <?= icon('inbox', 40) ?>
        <div>Nothing yet. When somebody types <strong>@<?= esc(explode(' ', current_user()['name'])[0]) ?></strong> on a ticket, it lands here.</div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="notif-list">
            <?php foreach ($notifications as $n): ?>
                <a class="notif <?= (int) $n['is_read'] === 0 ? 'is-unread' : '' ?>" href="/notifications/<?= (int) $n['id'] ?>">
                    <span class="notif-dot"></span>
                    <span class="notif-body">
                        <span class="notif-actor"><?= esc($n['actor_name'] ?? 'Someone') ?></span>
                        <span class="notif-text"><?= esc($n['body']) ?></span>
                        <span class="notif-time"><?= esc(date('M j, g:i A', strtotime((string) $n['created_at']))) ?></span>
                    </span>
                </a>
            <?php endforeach ?>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
