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
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
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

<div class="form-card password-card">
    <h2 class="section-title"><?= icon('lock', 16) ?> Password</h2>
    <?= $this->include('account/_password_form') ?>
</div>

<div class="form-card mfa-card">
    <h2 class="section-title"><?= icon('shield', 16) ?> Two-step sign-in</h2>
    <?php if ($mfaEnabled): ?>
        <p>On. Password sign ins also ask for a code from your authenticator app. <?= (int) $mfaCodes ?> recovery code<?= (int) $mfaCodes === 1 ? '' : 's' ?> left.</p>
        <div class="form-grid">
            <form action="/account/mfa/recovery" method="post" class="form-group">
                <?= csrf_field() ?>
                <label for="mfa-code-recovery">Current code from your app</label>
                <input type="text" id="mfa-code-recovery" name="code" class="mfa-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required>
                <div class="field-hint">Makes 10 new recovery codes and cancels the old ones.</div>
                <button type="submit" class="btn btn-secondary"><?= icon('lock', 16) ?> New recovery codes</button>
            </form>
            <?php if (! $mfaRequired): ?>
                <form action="/account/mfa/disable" method="post" class="form-group">
                    <?= csrf_field() ?>
                    <label for="mfa-code-disable">Current code from your app</label>
                    <input type="text" id="mfa-code-disable" name="code" class="mfa-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required>
                    <div class="field-hint">Your password alone will sign you in again.</div>
                    <button type="submit" class="btn btn-secondary"><?= icon('flag', 16) ?> Turn off</button>
                </form>
            <?php endif ?>
        </div>
    <?php else: ?>
        <p>Off. Add a code from your phone so a stolen password is not enough to get in.</p>
        <a href="/account/mfa/setup" class="btn"><?= icon('shield', 16) ?> Set up two-step sign-in</a>
    <?php endif ?>
    <?php if ($mfaRequired): ?>
        <div class="field-hint">Your role must keep this on. If you lose your phone and your recovery codes, ask a super admin to reset it.</div>
    <?php endif ?>
</div>

<?= $this->endSection() ?>
