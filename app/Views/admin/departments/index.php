<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>Departments &amp; Roles</h1>
        <div class="subtitle">
            <?php if ($isSuper): ?>
                Every department, who runs it, and what the assistant is allowed to know.
            <?php else: ?>
                The departments you run. Edit their roles and what the assistant knows.
            <?php endif ?>
        </div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<?php if ($canCreate): ?>
    <div class="form-card">
        <form action="/admin/departments" method="post" class="row-form">
            <?= csrf_field() ?>
            <input type="text" name="name" maxlength="80" placeholder="New department name" required>
            <button type="submit" class="btn btn-sm"><?= icon('plus', 15) ?> Add department</button>
        </form>
    </div>

    <div class="note-strip">
        <?= icon('sparkle', 15) ?>
        <span>
            Company-wide: <?= (int) $globalArticles ?> article<?= (int) $globalArticles === 1 ? '' : 's' ?>
            and <?= (int) $globalGuardrails ?> guardrail<?= (int) $globalGuardrails === 1 ? '' : 's' ?> apply to every department.
            <a href="/admin/departments/0/knowledge">Manage company-wide knowledge</a>
        </span>
    </div>
<?php else: ?>
    <div class="note-strip">
        <?= icon('shield', 15) ?>
        <span>You can edit the roles, leadership and AI knowledge of the departments you run. Only a super admin can add or remove a department.</span>
    </div>
<?php endif ?>

<?php if (empty($departments)): ?>
    <div class="empty-state">
        <?= icon('department', 40) ?>
        <div><?= $isSuper ? 'No departments yet — add one above.' : 'No departments are assigned to you yet. Ask a super admin.' ?></div>
    </div>
<?php endif ?>

<form action="/admin/departments/bulk" method="post" id="dept-form">
    <?= csrf_field() ?>

    <?php foreach ($departments as $d): ?>
        <?php $id = (int) $d['id']; $hasCtx = (int) $d['article_count'] > 0; ?>
        <section class="dept-card <?= (int) $d['is_active'] === 1 ? '' : 'is-off' ?>">
            <header class="dept-title">
                <div class="dept-name">
                    <label for="n-<?= $id ?>">Department name</label>
                    <input type="text" id="n-<?= $id ?>" name="dept[<?= $id ?>][name]" value="<?= esc($d['name']) ?>" maxlength="80" required>
                </div>

                <div class="dept-stats">
                    <span class="badge-outline"><?= (int) $d['staff_count'] ?> staff</span>
                    <span class="badge-outline"><?= count($d['roles']) ?> role<?= count($d['roles']) === 1 ? '' : 's' ?></span>
                    <span class="badge-kind <?= $hasCtx ? 'badge-kind-suggestion' : 'badge-kind-handoff' ?>">
                        <?= $hasCtx ? 'AI briefed' : 'AI has no context' ?>
                    </span>
                </div>
            </header>

            <div class="dept-grid">
                <?php if ($isSuper): ?>
                    <div class="field">
                        <label for="sv-<?= $id ?>">Supervisor</label>
                        <select id="sv-<?= $id ?>" name="dept[<?= $id ?>][supervisor_id]">
                            <option value="">Unassigned</option>
                            <?php foreach ($supervisors as $sv): ?>
                                <option value="<?= (int) $sv['id'] ?>" <?= (int) ($d['supervisor_id'] ?? 0) === (int) $sv['id'] ? 'selected' : '' ?>><?= esc($sv['name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                <?php endif ?>

                <div class="field">
                    <label for="tl-<?= $id ?>">Team Leader</label>
                    <select id="tl-<?= $id ?>" name="dept[<?= $id ?>][team_leader_id]">
                        <option value="">Unassigned</option>
                        <?php foreach ($d['leads'] as $ld): ?>
                            <option value="<?= (int) $ld['id'] ?>" <?= (int) ($d['team_leader_id'] ?? 0) === (int) $ld['id'] ? 'selected' : '' ?>><?= esc($ld['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                    <div class="field-hint">Promoted to Team Leader automatically.</div>
                </div>

                <div class="field">
                    <label for="h-<?= $id ?>">Handles</label>
                    <input type="text" id="h-<?= $id ?>" name="dept[<?= $id ?>][handles]" value="<?= esc($d['handles'] ?? '') ?>" maxlength="150"
                           placeholder="e.g. IT support, accounts, email, printers">
                </div>

                <div class="field">
                    <label for="act-<?= $id ?>">Status</label>
                    <select id="act-<?= $id ?>" name="dept[<?= $id ?>][is_active]">
                        <option value="1" <?= (int) $d['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= (int) $d['is_active'] === 0 ? 'selected' : '' ?>>Hidden from new tickets</option>
                    </select>
                </div>
            </div>

            <div class="note-strip">
                <?= icon('sparkle', 15) ?>
                <span>
                    <?= (int) $d['article_count'] ?> knowledge article<?= (int) $d['article_count'] === 1 ? '' : 's' ?>
                    and <?= (int) $d['guardrail_count'] ?> guardrail<?= (int) $d['guardrail_count'] === 1 ? '' : 's' ?>.
                    <?php if ((int) $d['article_count'] === 0): ?>
                        With no articles the assistant never suggests a fix here — every ticket goes to a person.
                    <?php endif ?>
                    <a href="/admin/departments/<?= $id ?>/knowledge">Manage what the assistant knows</a>
                </span>
            </div>

            <div class="dept-roles">
                <label>Roles in this department</label>
                <div class="role-list">
                    <?php foreach ($d['roles'] as $r): ?>
                        <div class="role-row">
                            <input type="text" name="role[<?= (int) $r['id'] ?>][name]" value="<?= esc($r['name']) ?>" maxlength="80" aria-label="Role name">
                            <input type="text" class="role-handles" name="role[<?= (int) $r['id'] ?>][handles]" value="<?= esc($r['handles'] ?? '') ?>" maxlength="150"
                                   placeholder="Handles: e.g. leave, sick leave, attendance" aria-label="What <?= esc($r['name']) ?> handles">
                            <a class="chip-x" href="#" data-delete-role="<?= (int) $r['id'] ?>" title="Delete role">&times;</a>
                        </div>
                    <?php endforeach ?>
                    <div class="role-row role-add">
                        <input type="text" name="newrole[<?= $id ?>][name]" maxlength="80" placeholder="+ add role" aria-label="New role name">
                        <input type="text" class="role-handles" name="newrole[<?= $id ?>][handles]" maxlength="150" placeholder="What it handles" aria-label="What the new role handles">
                    </div>
                </div>
                <div class="field-hint">"Handles" is how the assistant checks a ticket reached the right team. Employees also see it when they pick a role.</div>
            </div>
        </section>
    <?php endforeach ?>

    <?php if (! empty($departments)): ?>
        <div class="save-bar">
            <span class="save-note" id="save-note">Edit anything above, then save it all in one go.</span>
            <button type="submit" class="btn"><?= icon('check', 16) ?> Save all changes</button>
        </div>
    <?php endif ?>
</form>

<?php foreach ($departments as $d): ?>
    <?php foreach ($d['roles'] as $r): ?>
        <form action="/admin/departments/roles/<?= (int) $r['id'] ?>/delete" method="post" id="delrole-<?= (int) $r['id'] ?>" class="hidden-form">
            <?= csrf_field() ?>
        </form>
    <?php endforeach ?>
<?php endforeach ?>

<script>
(function () {
    document.querySelectorAll('[data-delete-role]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var id = a.getAttribute('data-delete-role');
            var name = a.parentNode.querySelector('input').value;
            if (confirm('Delete the "' + name + '" role?')) {
                document.getElementById('delrole-' + id).submit();
            }
        });
    });

    var form = document.getElementById('dept-form');
    var note = document.getElementById('save-note');
    if (!form || !note) return;
    var dirty = false;
    form.addEventListener('input', function () {
        if (dirty) return;
        dirty = true;
        note.textContent = 'You have unsaved changes.';
        note.classList.add('is-dirty');
    });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>

<?= $this->endSection() ?>
