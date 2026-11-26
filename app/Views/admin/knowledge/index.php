<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    $isGlobal = $department['id'] === null;
    $base     = '/admin/departments/' . (int) $pageId;

    $kinds = [
        'instruction' => [
            'label' => 'Rule for the assistant',
            'badge' => 'badge-kind-acknowledge',
            'hint'  => 'Sent with every request. The model usually follows it, but nothing checks.',
            'eg'    => 'Never promise a delivery date.',
        ],
        'handoff' => [
            'label' => 'Always hand to a person',
            'badge' => 'badge-kind-handoff',
            'hint'  => 'A word or phrase. If the employee mentions it, the assistant gives no advice and routes the ticket. The app enforces this, not the model.',
            'eg'    => 'payroll',
        ],
        'block' => [
            'label' => 'Never say',
            'badge' => 'badge-kind-suggestion',
            'hint'  => 'A word or phrase the reply must not contain. A reply that does is replaced before anyone sees it. The app enforces this, not the model.',
            'eg'    => 'refund',
        ],
    ];

    $byKind = array_fill_keys(array_keys($kinds), []);
    foreach ($rules as $rule) {
        $byKind[$rule['kind']][] = $rule;
    }
?>

<a href="/admin/departments" class="back-link"><?= icon('chevron-left', 14) ?> Back to departments</a>

<div class="page-header">
    <div>
        <h1><?= esc($department['name']) ?>: assistant knowledge</h1>
        <div class="subtitle">
            <?php if ($isGlobal): ?>
                Articles and guardrails here apply to every department, on top of each department's own.
            <?php else: ?>
                Short articles the assistant may answer from, and the rules it has to keep. Only the articles that match a ticket are sent with it.
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

<h3 class="section-title"><?= icon('search', 16) ?> Test it</h3>
<div class="form-card">
    <form action="<?= $base ?>/knowledge" method="get" class="row-form">
        <input type="text" name="try" value="<?= esc($try) ?>" maxlength="500" placeholder="Type a request the way an employee would, e.g. I can't log in to the portal">
        <button type="submit" class="btn btn-sm btn-secondary"><?= icon('search', 15) ?> See what gets sent</button>
    </form>

    <?php if ($preview !== null): ?>
        <div class="field-hint">For that request the assistant would receive:</div>
        <?php if ($preview['articles'] === []): ?>
            <div class="note-strip"><?= icon('flag', 15) ?> <span>No articles match, so it would give no advice and route the ticket. Add keywords to an article if it should have matched.</span></div>
        <?php else: ?>
            <ol>
                <?php foreach ($preview['articles'] as $a): ?>
                    <li>
                        <strong><?= esc($a['title']) ?></strong>
                        <?php if ($a['department_id'] === null && ! $isGlobal): ?><span class="badge-outline">company-wide</span><?php endif ?>
                        <?php if (isset($a['score'])): ?><span class="badge-outline">match <?= esc(number_format((float) $a['score'], 2)) ?></span><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>
        <?php if ($preview['handoff'] !== null): ?>
            <div class="note-strip"><?= icon('shield', 15) ?> <span>Mentions the hand-off topic <strong><?= esc($preview['handoff']) ?></strong>, so no advice is given whatever the model says.</span></div>
        <?php endif ?>
        <?php if ($preview['blocked'] !== null): ?>
            <div class="note-strip"><?= icon('shield', 15) ?> <span>If the assistant wrote this, it would be replaced: it contains <strong><?= esc($preview['blocked']) ?></strong>.</span></div>
        <?php endif ?>
        <?php if (count($preview['routing'] ?? []) > 1): ?>
            <div class="field-hint">Raised with <?= esc($department['name']) ?>, the assistant would check it against these teams and may move it:</div>
            <ol>
                <?php foreach ($preview['routing'] as $i => $team): ?>
                    <li>
                        <strong><?= esc(\App\Libraries\RoutingDirectory::label($team)) ?></strong>
                        <?php if ($i === 0): ?><span class="badge-outline">their pick</span><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php elseif ($department['id'] !== null): ?>
            <div class="field-hint">No other team's "Handles" matches this, so it would stay with <?= esc($department['name']) ?>.</div>
        <?php endif ?>
    <?php endif ?>
</div>

<h3 class="section-title"><?= icon('inbox', 16) ?> Articles</h3>

<div class="form-card">
    <form action="<?= $base ?>/knowledge" method="post">
        <?= csrf_field() ?>
        <div class="dept-grid">
            <div class="field">
                <label for="new-title">Title</label>
                <input type="text" id="new-title" name="title" maxlength="<?= (int) $limits['title'] ?>" value="<?= esc(old('title')) ?>" placeholder="VPN keeps disconnecting" required>
            </div>
            <div class="field">
                <label for="new-keywords">Other words people use</label>
                <input type="text" id="new-keywords" name="keywords" maxlength="<?= (int) $limits['keywords'] ?>" value="<?= esc(old('keywords')) ?>" placeholder="remote access, forticlient, drops, offline">
            </div>
        </div>
        <div class="field">
            <label for="new-body">What to tell them</label>
            <textarea id="new-body" name="body" rows="5" maxlength="<?= (int) $limits['body'] ?>" placeholder="One problem per article. The steps, in order, exactly as your team would give them." required><?= esc(old('body')) ?></textarea>
            <div class="field-hint">Up to <?= number_format((int) $limits['body']) ?> characters. One problem per article works best: the assistant only receives the two or three that match a ticket.</div>
        </div>
        <button type="submit" class="btn btn-sm"><?= icon('plus', 15) ?> Add article</button>
    </form>
</div>

<?php if ($articles === []): ?>
    <div class="empty-state">
        <?= icon('inbox', 40) ?>
        <div>No articles yet<?= $isGlobal ? '' : ' — until there are, the assistant never suggests a fix for this department' ?>.</div>
    </div>
<?php endif ?>

<?php foreach ($articles as $a): ?>
    <section class="dept-card <?= (int) $a['is_active'] === 1 ? '' : 'is-off' ?>">
        <form action="/admin/departments/knowledge/<?= (int) $a['id'] ?>" method="post">
            <?= csrf_field() ?>
            <div class="dept-grid">
                <div class="field">
                    <label for="t-<?= (int) $a['id'] ?>">Title</label>
                    <input type="text" id="t-<?= (int) $a['id'] ?>" name="title" maxlength="<?= (int) $limits['title'] ?>" value="<?= esc($a['title']) ?>" required>
                </div>
                <div class="field">
                    <label for="k-<?= (int) $a['id'] ?>">Other words people use</label>
                    <input type="text" id="k-<?= (int) $a['id'] ?>" name="keywords" maxlength="<?= (int) $limits['keywords'] ?>" value="<?= esc($a['keywords'] ?? '') ?>">
                </div>
            </div>
            <div class="field">
                <label for="b-<?= (int) $a['id'] ?>">What to tell them</label>
                <textarea id="b-<?= (int) $a['id'] ?>" name="body" rows="4" maxlength="<?= (int) $limits['body'] ?>" required><?= esc($a['body']) ?></textarea>
            </div>
            <div class="row-actions">
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $a['is_active'] === 1 ? 'checked' : '' ?>>
                    In use
                </label>
                <button type="submit" class="btn btn-sm btn-secondary"><?= icon('check', 15) ?> Save</button>
            </div>
        </form>
        <form action="/admin/departments/knowledge/<?= (int) $a['id'] ?>/delete" method="post"
              onsubmit="return confirm('Delete this article?')" class="row-actions">
            <?= csrf_field() ?>
            <a href="/admin/departments/knowledge/<?= (int) $a['id'] ?>/history" class="btn-link">
                History<?= ($revisions[(int) $a['id']] ?? 0) > 0 ? ' (' . (int) $revisions[(int) $a['id']] . ')' : '' ?>
            </a>
            <?php if (($citedCount[(int) $a['id']] ?? 0) > 0): ?>
                <span class="badge-outline">quoted on <?= (int) $citedCount[(int) $a['id']] ?> ticket<?= (int) $citedCount[(int) $a['id']] === 1 ? '' : 's' ?></span>
            <?php endif ?>
            <button type="submit" class="btn-link is-danger">Delete article</button>
        </form>
    </section>
<?php endforeach ?>

<h3 class="section-title"><?= icon('shield', 16) ?> Guardrails</h3>

<div class="form-card">
    <form action="<?= $base ?>/guardrails" method="post" class="row-form">
        <?= csrf_field() ?>
        <select name="kind" required>
            <?php foreach ($kinds as $key => $k): ?>
                <option value="<?= esc($key) ?>" <?= old('kind') === $key ? 'selected' : '' ?>><?= esc($k['label']) ?></option>
            <?php endforeach ?>
        </select>
        <input type="text" name="value" maxlength="300" value="<?= esc(old('value')) ?>" placeholder="payroll" required>
        <button type="submit" class="btn btn-sm"><?= icon('plus', 15) ?> Add guardrail</button>
    </form>
    <div class="field-hint">
        Words and phrases match whole words, ignoring case. End one with * to match any ending: <strong>salar*</strong> catches salary and salaries.
    </div>
</div>

<?php foreach ($kinds as $key => $k): ?>
    <div class="side-panel">
        <h3><span class="badge-kind <?= esc($k['badge']) ?>"><?= esc($k['label']) ?></span></h3>
        <div class="field-hint"><?= esc($k['hint']) ?> Example: <em><?= esc($k['eg']) ?></em></div>
        <?php if ($byKind[$key] === []): ?>
            <div class="field-hint">None yet.</div>
        <?php else: ?>
            <div class="role-chips" style="margin-top: 12px;">
                <?php foreach ($byKind[$key] as $rule): ?>
                    <form action="/admin/departments/guardrails/<?= (int) $rule['id'] ?>/delete" method="post" class="role-chip">
                        <?= csrf_field() ?>
                        <span><?= esc($rule['value']) ?></span>
                        <button type="submit" class="chip-x" title="Remove" aria-label="Remove guardrail">&times;</button>
                    </form>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>
<?php endforeach ?>

<?= $this->endSection() ?>
