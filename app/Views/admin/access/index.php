<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>Sign-up access</h1>
        <div class="subtitle">Who can create their own account. Add a partner agency's domain, or one person's address.</div>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="note-strip">
    <?= icon('building', 15) ?>
    <span>
        <?php if ($companyDomains === []): ?>
            No company domains are set on the server, so <strong>anyone</strong> can sign up right now. Set ALLOWED_EMAIL_DOMAINS in the server's .env to limit it.
        <?php else: ?>
            Company domains, set on the server:
            <strong><?= esc(implode(', ', array_map(static fn ($d) => '@' . $d, $companyDomains))) ?></strong>.
            Everything below is added on top of these.
        <?php endif ?>
    </span>
</div>

<h3 class="section-title"><?= icon('plus', 16) ?> Let someone in</h3>
<div class="form-card">
    <form action="/admin/access" method="post">
        <?= csrf_field() ?>
        <div class="dept-grid">
            <div class="field">
                <label for="access-value">Domain or email address</label>
                <input type="text" id="access-value" name="value" maxlength="255" value="<?= esc(old('value')) ?>" placeholder="partneragency.com.au  or  jane@partneragency.com" required>
            </div>
            <div class="field">
                <label for="access-label">Agency or note <span class="cell-muted">(optional)</span></label>
                <input type="text" id="access-label" name="label" maxlength="<?= (int) $labelMax ?>" value="<?= esc(old('label')) ?>" placeholder="Partner Agency Ltd">
            </div>
        </div>
        <div class="field-hint">
            A domain lets everyone at that agency sign up (subdomains too). An address lets in just that person.
            Free services like Gmail can only be added one address at a time. People still confirm their address with an emailed code.
        </div>
        <button type="submit" class="btn btn-sm"><?= icon('plus', 15) ?> Add</button>
    </form>
</div>

<h3 class="section-title"><?= icon('users', 16) ?> Partners and extra addresses</h3>

<?php if ($entries === []): ?>
    <div class="empty-state">
        <?= icon('users', 40) ?>
        <div>Nobody extra yet - only the company domains can sign up.</div>
    </div>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Who</th>
                    <th>Type</th>
                    <th>Agency or note</th>
                    <th>Added</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php $isDomain = $entry['kind'] === \App\Models\SignupAllowlistModel::KIND_DOMAIN; ?>
                    <tr>
                        <td><strong><?= esc(($isDomain ? '@' : '') . $entry['value']) ?></strong></td>
                        <td><span class="badge-outline"><?= $isDomain ? 'Whole domain' : 'One person' ?></span></td>
                        <td class="cell-muted"><?= esc($entry['label'] ?? '—') ?></td>
                        <td class="cell-muted"><?= esc(date('M j, Y', strtotime((string) ($entry['created_at'] ?? 'now')))) ?></td>
                        <td>
                            <form action="/admin/access/<?= (int) $entry['id'] ?>/delete" method="post"
                                  onsubmit="return confirm('Stop new sign-ups from <?= esc(($isDomain ? '@' : '') . $entry['value'], 'js') ?>? Existing accounts are not affected.');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-link is-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
