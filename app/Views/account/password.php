<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1><?= $forced ? 'Choose a new password' : 'Change password' ?></h1>
        <div class="subtitle"><?= $forced ? 'Your password was set by an admin. Pick your own to continue.' : 'Update the password you use to sign in.' ?></div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="form-card password-card">
    <?= $this->include('account/_password_form') ?>
    <?php if ($forced): ?>
        <form action="/logout" method="post" class="password-logout">
            <?= csrf_field() ?>
            <button type="submit" class="btn-link">Log out instead</button>
        </form>
    <?php endif ?>
</div>

<?= $this->endSection() ?>
