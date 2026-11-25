<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>Knowledge review</h1>
        <div class="subtitle">Answers staff marked as the solution. The assistant only reuses an answer after you approve it.</div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<?php if ($pending === []): ?>
    <div class="empty-state">
        <?= icon('check', 40) ?>
        <div>Nothing waiting for review.</div>
    </div>
<?php endif ?>

<?php foreach ($pending as $p): ?>
    <section class="dept-card review-item">
        <div class="review-head">
            <a href="/tickets/<?= (int) $p['ticket_id'] ?>#conversation"><strong>#<?= (int) $p['ticket_id'] ?> <?= esc($p['request_title'] ?: 'Untitled ticket') ?></strong></a>
            <span class="badge-outline"><?= esc($p['department'] ?: 'No team') ?></span>
        </div>
        <div class="field-hint">
            By <?= esc($p['author_name']) ?>, <?= esc(format_when($p['created_at'])) ?>
        </div>
        <div class="msg-text review-text"><?= esc($p['body']) ?></div>
        <form action="/admin/knowledge-review/<?= (int) $p['id'] ?>" method="post" class="row-actions">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="approve" class="btn btn-sm"><?= icon('check', 15) ?> Approve</button>
            <button type="submit" name="action" value="article" class="btn btn-sm btn-secondary"><?= icon('plus', 15) ?> Approve and save as article</button>
            <button type="submit" name="action" value="reject" class="btn-link is-danger" onclick="return confirm('Reject this answer? The assistant will not reuse it.')">Reject</button>
        </form>
    </section>
<?php endforeach ?>

<?= $this->endSection() ?>
