<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1><?= esc($title) ?></h1>
        <div class="subtitle">Save these recovery codes now. This is the only time they are shown.</div>
    </div>
</div>

<div class="alert alert-success"><?= icon('check', 16) ?> Each code works once if you lose your phone. Store them in a password manager or print them.</div>

<div class="form-card narrow">
    <ol class="mfa-codes">
        <?php foreach ($codes as $code): ?>
            <li><?= esc($code) ?></li>
        <?php endforeach ?>
    </ol>
    <a href="/tickets" class="btn"><?= icon('check', 16) ?> I saved my codes</a>
</div>

<?= $this->endSection() ?>
