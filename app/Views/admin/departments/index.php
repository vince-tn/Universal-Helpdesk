<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>Departments &amp; Roles</h1>
        <div class="subtitle">Requesters pick a department when raising a ticket. Agents are scoped to one.</div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="form-card">
    <form action="/admin/departments" method="post" class="row-form">
        <?= csrf_field() ?>
        <input type="text" name="name" maxlength="80" placeholder="New department name" required>
        <button type="submit" class="btn btn-sm"><?= icon('plus', 15) ?> Add department</button>
    </form>
</div>

<?php if (empty($departments)): ?>
    <div class="empty-state"><?= icon('department', 40) ?><div>No departments yet — add one above.</div></div>
<?php endif ?>

<?php foreach ($departments as $d): ?>
    <div class="dept-card <?= (int) $d['is_active'] === 1 ? '' : 'is-off' ?>">
        <div class="dept-head">
            <form action="/admin/departments/<?= (int) $d['id'] ?>" method="post" class="row-form">
                <?= csrf_field() ?>
                <input type="text" name="name" value="<?= esc($d['name']) ?>" maxlength="80" required>
                <label class="solution-toggle">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $d['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Active</span>
                </label>
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('check', 14) ?> Save</button>
            </form>

            <div class="dept-meta">
                <span class="badge-outline"><?= (int) $d['agent_count'] ?> agent<?= (int) $d['agent_count'] === 1 ? '' : 's' ?></span>
                <form action="/admin/departments/<?= (int) $d['id'] ?>/delete" method="post"
                      onsubmit="return confirm('Delete <?= esc($d['name'], 'js') ?> and its roles?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>

        <div class="role-list">
            <?php if (empty($d['roles'])): ?>
                <div class="role-empty">No roles in this department yet.</div>
            <?php endif ?>

            <?php foreach ($d['roles'] as $r): ?>
                <div class="role-row">
                    <form action="/admin/departments/roles/<?= (int) $r['id'] ?>" method="post" class="row-form">
                        <?= csrf_field() ?>
                        <input type="text" name="name" value="<?= esc($r['name']) ?>" maxlength="80" required>
                        <button type="submit" class="btn-link">Save</button>
                    </form>
                    <form action="/admin/departments/roles/<?= (int) $r['id'] ?>/delete" method="post"
                          onsubmit="return confirm('Delete the <?= esc($r['name'], 'js') ?> role?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-link is-danger">Delete</button>
                    </form>
                </div>
            <?php endforeach ?>

            <form action="/admin/departments/<?= (int) $d['id'] ?>/roles" method="post" class="row-form role-add">
                <?= csrf_field() ?>
                <input type="text" name="name" maxlength="80" placeholder="Add a role to <?= esc($d['name']) ?>" required>
                <button type="submit" class="btn-link">Add role</button>
            </form>
        </div>
    </div>
<?php endforeach ?>

<?= $this->endSection() ?>
