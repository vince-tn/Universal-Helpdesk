<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>SLA targets</h1>
        <div class="subtitle">How fast each kind of ticket gets a first reply and a fix. Enter hours, decimals are fine (0.5 is 30 minutes).</div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="side-panel sla-signoff">
    <h3><?= icon('shield', 16) ?> Sign-off</h3>
    <?php if ($last === null): ?>
        <div class="note-strip"><?= icon('flag', 15) ?> <span>These targets have not been signed off yet.</span></div>
    <?php else: ?>
        <div>
            Last signed off by <strong><?= esc($last['name']) ?></strong> on <?= esc(format_when($last['created_at'])) ?>.
            <?php if (! empty($last['note'])): ?><div class="field-hint">Note: <?= esc($last['note']) ?></div><?php endif ?>
        </div>
        <?php if ($changed): ?>
            <div class="note-strip"><?= icon('flag', 15) ?> <span>Changed since last sign-off. Sign off again once the new targets are agreed.</span></div>
        <?php else: ?>
            <div class="field-hint"><?= icon('check', 13) ?> No changes since then.</div>
        <?php endif ?>
    <?php endif ?>

    <form action="/admin/sla/signoff" method="post" class="row-form sla-signoff-form"
          onsubmit="return confirm('Sign off the targets as they are now?')">
        <?= csrf_field() ?>
        <input type="text" name="note" maxlength="500" value="<?= esc((string) old('note', '', false)) ?>" placeholder="Note, e.g. agreed with ops and HR on 26 Nov">
        <button type="submit" class="btn btn-sm"><?= icon('check', 15) ?> Sign off these targets</button>
    </form>
</div>

<form action="/admin/sla" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <table class="sla-table">
            <thead>
                <tr>
                    <th>Priority</th>
                    <th>First response (hours)</th>
                    <th>Resolution (hours)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped as $category => $rows): ?>
                    <tr class="sla-group">
                        <td colspan="3"><strong><?= esc($category) ?></strong></td>
                    </tr>
                    <?php foreach ($rows as $p): ?>
                        <?php
                            $pid  = (int) $p['id'];
                            $resp = (string) (old('sla.' . $pid . '.response', null, false) ?? \App\Libraries\SlaTargets::hours((int) $p['response_minutes']));
                            $res  = (string) (old('sla.' . $pid . '.resolution', null, false) ?? \App\Libraries\SlaTargets::hours((int) $p['resolution_minutes']));
                        ?>
                        <tr>
                            <td><span class="pill pill-priority-<?= esc(strtolower($p['priority'])) ?>"><?= esc($p['priority']) ?></span></td>
                            <td>
                                <input type="text" inputmode="decimal" class="sla-hours" name="sla[<?= $pid ?>][response]" value="<?= esc($resp) ?>"
                                       aria-label="<?= esc($category . ' ' . $p['priority'] . ' first response in hours') ?>" required>
                                <span class="field-hint">Now <?= esc(\App\Libraries\Sla::minutesLabel((int) $p['response_minutes'])) ?></span>
                            </td>
                            <td>
                                <input type="text" inputmode="decimal" class="sla-hours" name="sla[<?= $pid ?>][resolution]" value="<?= esc($res) ?>"
                                       aria-label="<?= esc($category . ' ' . $p['priority'] . ' resolution in hours') ?>" required>
                                <span class="field-hint">Now <?= esc(\App\Libraries\Sla::minutesLabel((int) $p['resolution_minutes'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach ?>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <div class="field-hint">First response must be shorter than resolution. Max 90 days. Changes apply to new tickets, and to open tickets when their priority or category changes.</div>
    <div class="row-actions sla-save">
        <button type="submit" class="btn"><?= icon('check', 16) ?> Save targets</button>
    </div>
</form>

<?= $this->endSection() ?>
