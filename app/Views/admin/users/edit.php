<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<a href="/admin/users" class="back-link"><?= icon('chevron-left', 14) ?> Back to accounts</a>

<div class="page-header">
    <div>
        <h1><?= esc($editUser['name']) ?></h1>
        <div class="subtitle"><?= esc($editUser['email']) ?></div>
    </div>
</div>

<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="form-card narrow">
    <form action="/admin/users/<?= $editUser['id'] ?>" method="post">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="name">Full name</label>
            <input type="text" id="name" name="name" value="<?= esc($editUser['name']) ?>" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" value="<?= esc($editUser['email']) ?>" disabled>
            <div class="field-hint">Email can't be changed here.</div>
        </div>

        <div class="form-group">
            <label for="password">Reset password</label>
            <input type="password" id="password" name="password" minlength="8" placeholder="Leave blank to keep current password">
        </div>

        <div class="form-group">
            <label for="company">Company</label>
            <select id="company" name="company">
                <option value="">Not set</option>
                <?php foreach (companies() as $co): ?>
                    <option value="<?= esc($co) ?>" <?= ($editUser['company'] ?? '') === $co ? 'selected' : '' ?>><?= esc($co) ?></option>
                <?php endforeach ?>
            </select>
        </div>


        <div class="form-group">
            <label for="role">Role</label>
            <select id="role" name="role" onchange="document.getElementById('dept-group').style.display = ['agent','team_leader','supervisor'].indexOf(this.value) !== -1 ? 'block' : 'none'">
                <option value="requester" <?= ($editUser['role'] ?? '') === 'requester' ? 'selected' : '' ?>><?= role_label('requester') ?></option>
                <option value="agent" <?= ($editUser['role'] ?? '') === 'agent' ? 'selected' : '' ?>><?= role_label('agent') ?></option>
                <option value="team_leader" <?= ($editUser['role'] ?? '') === 'team_leader' ? 'selected' : '' ?>><?= role_label('team_leader') ?></option>
                <option value="supervisor" <?= ($editUser['role'] ?? '') === 'supervisor' ? 'selected' : '' ?>><?= role_label('supervisor') ?></option>
                <option value="superadmin" <?= ($editUser['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>><?= role_label('superadmin') ?></option>
            </select>
        </div>

        <div class="form-group" id="dept-group" style="display:<?= in_array($editUser['role'], ['agent','team_leader','supervisor'], true) ? 'block' : 'none' ?>">
            <label for="department">Department</label>
            <select id="department" name="department">
                <option value="">Select a department...</option>
                <?php foreach (departments() as $dept): ?>
                    <option value="<?= esc($dept) ?>" <?= $editUser['department'] === $dept ? 'selected' : '' ?>><?= esc($dept) ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="form-group" id="role-group" style="display:<?= in_array($editUser['role'], ['agent','team_leader'], true) ? 'block' : 'none' ?>">
            <label for="department_role_id">Role in department</label>
            <select id="department_role_id" name="department_role_id" data-selected="<?= (int) ($editUser['department_role_id'] ?? 0) ?>">
                <option value="">No specific role</option>
            </select>
            <div class="field-hint">Optional. The job title within that department.</div>
        </div>


        <div class="form-group checkbox-row">
            <input type="checkbox" id="can_edit_knowledge" name="can_edit_knowledge" value="1" <?= (int) ($editUser['can_edit_knowledge'] ?? 0) === 1 ? 'checked' : '' ?>>
            <label for="can_edit_knowledge">May edit their department's AI knowledge</label>
        </div>
        <div class="field-hint">Only adds anything for an agent. Supervisors and team leaders already edit the guides for the departments they run.</div>

        <div class="form-group checkbox-row">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $editUser['is_active'] === 1 ? 'checked' : '' ?>>
            <label for="is_active">Account active</label>
        </div>

        <button type="submit" class="btn"><?= icon('check', 16) ?> Save changes</button>
    </form>

<script>
window.UHD_DEPT_ROLES = <?= json_encode($deptRoles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
(function () {
    var dept = document.getElementById('department');
    var role = document.getElementById('department_role_id');
    var kind = document.getElementById('role');
    var group = document.getElementById('role-group');
    if (!dept || !role) return;

    function fill() {
        var want = role.getAttribute('data-selected') || '';
        var list = (window.UHD_DEPT_ROLES || {})[dept.value] || [];
        role.innerHTML = '<option value="">No specific role</option>';
        list.forEach(function (r) {
            var o = document.createElement('option');
            o.value = r.id; o.textContent = r.name;
            if (String(r.id) === String(want)) o.selected = true;
            role.appendChild(o);
        });
        if (group) group.style.display = ['agent','team_leader'].indexOf(kind.value) !== -1 ? 'block' : 'none';
    }

    dept.addEventListener('change', function () { role.setAttribute('data-selected', ''); fill(); });
    kind.addEventListener('change', fill);
    fill();
})();
</script>
</div>

<?= $this->endSection() ?>
