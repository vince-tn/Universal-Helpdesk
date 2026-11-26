<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<a href="/admin/departments/<?= (int) $pageId ?>/knowledge" class="back-link"><?= icon('chevron-left', 14) ?> Back to <?= esc($department) ?> knowledge</a>

<div class="page-header">
    <div>
        <h1><?= esc($title) ?></h1>
        <div class="subtitle">
            <?php if ($article === null): ?>
                This article has been deleted. Its history is kept so answers given from it can still be explained.
            <?php else: ?>
                Every version of this article, newest first, and the tickets the assistant quoted it on.
            <?php endif ?>
        </div>
    </div>
</div>

<h3 class="section-title"><?= icon('ticket', 16) ?> Quoted on</h3>

<?php if ($citations === []): ?>
    <div class="empty-state">
        <?= icon('ticket', 40) ?>
        <div>The assistant has not quoted this article on any ticket yet.</div>
    </div>
<?php else: ?>
    <div class="side-panel">
        <div class="field-hint">Each line is a ticket where this article's text went into what the requester read, and the version it was at.</div>
        <ul class="plain-list">
            <?php foreach ($citations as $c): ?>
                <li>
                    <a href="/tickets/<?= (int) $c['ticket_id'] ?>">#<?= (int) $c['ticket_id'] ?> <?= esc($c['request_title'] ?? 'Ticket') ?></a>
                    <span class="badge-outline"><?= esc($c['status'] ?? 'Unknown') ?></span>
                    <?php if ($c['revision_id'] !== null): ?>
                        <span class="badge-outline">version <?= (int) $c['revision_id'] ?></span>
                    <?php endif ?>
                    <span class="field-hint"><?= esc($c['created_at']) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<h3 class="section-title"><?= icon('clock', 16) ?> Versions</h3>

<?php if ($revisions === []): ?>
    <div class="empty-state">
        <?= icon('clock', 40) ?>
        <div>No history recorded for this article.</div>
    </div>
<?php endif ?>

<?php foreach ($revisions as $rev): ?>
    <section class="dept-card">
        <div class="row-actions">
            <span class="badge-outline">version <?= (int) $rev['id'] ?></span>
            <span class="badge-kind <?= $rev['action'] === 'deleted' ? 'badge-kind-handoff' : 'badge-kind-acknowledge' ?>"><?= esc($rev['action']) ?></span>
            <?php if ((int) $rev['is_active'] !== 1): ?><span class="badge-outline">not in use</span><?php endif ?>
            <span class="field-hint"><?= esc($rev['changed_by_name']) ?> &middot; <?= esc($rev['created_at']) ?></span>
        </div>
        <div class="field">
            <label>Title</label>
            <div><?= esc($rev['title']) ?></div>
        </div>
        <?php if (trim((string) ($rev['keywords'] ?? '')) !== ''): ?>
            <div class="field">
                <label>Other words people use</label>
                <div><?= esc($rev['keywords']) ?></div>
            </div>
        <?php endif ?>
        <div class="field">
            <label>What it told them</label>
            <pre class="plain-block"><?= esc($rev['body']) ?></pre>
        </div>
    </section>
<?php endforeach ?>

<?= $this->endSection() ?>
