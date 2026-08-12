<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    function rolePillClass(string $role): string {
        return match ($role) {
            'superadmin' => 'pill-role-superadmin',
            'agent' => 'pill-role-agent',
            default => 'pill-role-requester',
        };
    }
?>

<div class="page-header">
    <div>
        <h1>Manage Staff &amp; Accounts</h1>
        <div class="subtitle">Create agents scoped to a department, or promote another superadmin.</div>
    </div>
    <a href="/admin/users/new" class="btn"><?= icon('plus', 16) ?> New Account</a>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Department</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= esc($u['name']) ?></strong></td>
                    <td class="cell-muted"><?= esc($u['email']) ?></td>
                    <td><span class="pill <?= rolePillClass($u['role']) ?>"><?= esc($u['role']) ?></span></td>
                    <td class="cell-muted"><?= esc($u['department'] ?? '—') ?></td>
                    <td><?= (int) $u['is_active'] === 1 ? '<span class="pill pill-status-resolved">Active</span>' : '<span class="pill pill-status-closed">Disabled</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <a href="/admin/users/<?= $u['id'] ?>/edit" class="btn btn-secondary btn-sm"><?= icon('edit', 14) ?> Edit</a>
                            <form action="/admin/users/<?= $u['id'] ?>/toggle" method="post">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-link"><?= (int) $u['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form action="/admin/users/<?= $u['id'] ?>/delete" method="post"
                                  onsubmit="return confirm('Permanently delete <?= esc($u['name'], 'js') ?>? Their tickets stay, but they will be unassigned.');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-link is-danger">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
