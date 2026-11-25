<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>My settings</h1>
        <div class="subtitle">Choose how you hear about your tickets.</div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>

<div class="form-card">
    <form action="/account" method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group">
                <label for="department">Your department</label>
                <select id="department" name="department" <?= ($account['role'] ?? 'requester') !== 'requester' ? 'disabled' : '' ?>>
                    <option value="">Not set</option>
                    <?php foreach (departments() as $d): ?>
                        <option value="<?= esc($d) ?>" <?= ($account['department'] ?? '') === $d ? 'selected' : '' ?>><?= esc($d) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (($account['role'] ?? 'requester') !== 'requester'): ?>
                    <div class="field-hint">Staff departments are set by an admin because they control ticket access.</div>
                <?php endif ?>
            </div>
            <div class="form-group span-2">
                <label class="checkbox-row">
                    <input type="checkbox" name="notify_email" value="1" <?= (int) ($account['notify_email'] ?? 1) === 1 ? 'checked' : '' ?>>
                    Email me when a ticket I raised or handle is created, assigned, answered, changes status, needs approval or is close to its SLA
                </label>
                <div class="field-hint">In-app notifications always stay on.</div>
            </div>
        </div>
        <button type="submit" class="btn"><?= icon('check', 16) ?> Save</button>
    </form>
</div>

<?= $this->endSection() ?>
