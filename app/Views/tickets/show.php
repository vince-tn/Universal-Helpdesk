<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php
    $f    = $ticket['fields'] ?? $ticket;
    $user = current_user();
    $tid  = (string) ($ticket['id'] ?? '');

    $assistMessageId = (int) (session()->getFlashdata('assist_message_id') ?? 0);

    $isRequester = strcasecmp((string) ($f['Requester Email'] ?? ''), (string) ($user['email'] ?? '')) === 0;

    $priorityPill = static fn (string $priority): string => match ($priority) {
        'Low'    => 'pill-priority-low',
        'High'   => 'pill-priority-high',
        'Urgent' => 'pill-priority-urgent',
        default  => 'pill-priority-medium',
    };

    $statusPill = static fn (string $status): string => match ($status) {
        'In Progress', 'Reopened'                         => 'pill-status-in-progress',
        'Awaiting Requester', 'On Hold', 'Pending Approval' => 'pill-status-waiting',
        'Resolved'                                        => 'pill-status-resolved',
        'Closed', 'Cancelled'                             => 'pill-status-closed',
        default                                           => 'pill-status-new',
    };

    $slaClass = 'sla-' . str_replace('_', '-', $slaState);
    $answers  = json_decode((string) ($f['Service Fields'] ?? ''), true) ?: [];
    $isStaffView = $canManage;

    $needsReview   = (string) ($f['Needs Human Review'] ?? '0') === '1';

    $owner      = \App\Libraries\RoutingDirectory::owner($f);
    $ownerLabel = $owner['department'] === '' ? '—' : \App\Libraries\RoutingDirectory::label($owner);
    $ownerNote  = trim((string) ($f['Routing Note'] ?? '')) ?: 'chosen by requester';
?>

<a href="/tickets" class="back-link"><?= icon('chevron-left', 14) ?> Back to <?= $user['role'] === 'requester' ? 'my tickets' : 'dashboard' ?></a>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
<?php endif ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="page-header">
    <div>
        <h1><?= esc($f['Request Title'] ?? $f['Title'] ?? 'Ticket') ?></h1>
        <div class="subtitle">
            <span class="ticket-ref">#<?= esc($tid) ?></span>
            Submitted by <?= esc($f['Requester'] ?? '—') ?> · <?= esc(format_when($f['CreatedAt'] ?? null)) ?>
        </div>
    </div>
    <div class="table-actions">
        <span class="pill <?= $statusPill((string) ($f['Status'] ?? 'New')) ?>"><span class="dot"></span><?= esc($f['Status'] ?? 'New') ?></span>
        <span class="pill <?= $priorityPill($meta['priority'] ?? 'Medium') ?>"><span class="dot"></span><?= esc($meta['priority'] ?? 'Medium') ?></span>
        <?php if ((int) ($f['Confidential'] ?? 0) === 1): ?>
            <span class="pill pill-confidential"><?= icon('lock', 12) ?> Confidential</span>
        <?php endif ?>
    </div>
</div>

<div class="sla-strip <?= esc($slaClass) ?>">
    <div><span class="k">SLA</span><span class="v"><?= esc(\App\Libraries\Sla::label($slaState)) ?></span></div>
    <div><span class="k">First response due</span><span class="v"><?= esc(format_when($meta['response_due_at'] ?? null) ?: 'Not set') ?><?= ! empty($meta['first_response_at']) ? ' <span class="by-user">answered ' . esc(format_when($meta['first_response_at'])) . '</span>' : '' ?></span></div>
    <div><span class="k">Resolve by</span><span class="v"><?= esc(format_when($meta['due_at'] ?? null) ?: 'Not set') ?></span></div>
    <?php if (! empty($meta['needed_by'])): ?>
        <div><span class="k">Needed by</span><span class="v"><?= esc(format_day($meta['needed_by'])) ?></span></div>
    <?php endif ?>
</div>

<?php if ($approvals !== []): ?>
    <div class="approval-card">
        <h3 class="section-title"><?= icon('shield', 16) ?> Approval</h3>
        <ol class="approval-steps">
            <?php foreach ($approvals as $step): ?>
                <li class="step-<?= esc($step['status']) ?>">
                    <strong><?= esc(role_label($step['approver_role'])) ?></strong>
                    <span><?= esc(ucfirst($step['status'])) ?><?= ! empty($step['decided_at']) ? ' on ' . esc(format_when($step['decided_at'])) : '' ?></span>
                    <?php if (! empty($step['note'])): ?><div class="field-hint"><?= esc($step['note']) ?></div><?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
        <?php if ($canApprove): ?>
            <form action="/tickets/<?= esc($tid) ?>/approval" method="post" class="approval-form">
                <?= csrf_field() ?>
                <input type="text" name="note" maxlength="500" placeholder="Note (needed when you reject)">
                <button type="submit" name="decision" value="approve" class="btn btn-sm"><?= icon('check', 14) ?> Approve</button>
                <button type="submit" name="decision" value="reject" class="btn btn-sm btn-danger">Reject</button>
            </form>
        <?php endif ?>
    </div>
<?php endif ?>

<div class="detail-layout">
    <div>
        <div class="ai-tags" id="ai-tags">
            <div class="ai-tags-head">
                <span class="badge-ai"><?= icon('sparkle', 11) ?> AI</span>
                <span>Tagged automatically at intake</span>
                <span class="pill pill-source-<?= in_array(strtolower($f['AI Source'] ?? 'local'), ['gemini', 'openrouter'], true) ? 'gemini' : 'local' ?>">
                    <?= esc($f['AI Source'] ?? 'Local') ?>
                </span>
                <?php if ($needsReview): ?>
                    <span class="badge-kind badge-kind-handoff">Flagged for review</span>
                <?php endif ?>
            </div>
            <div class="ai-tags-grid">
                <div><span class="k">Handled by</span><span class="v"><?= esc($ownerLabel) ?> <span class="by-user"><?= esc($ownerNote) ?></span></span></div>
                <?php if (! empty($f['Routing Reason'])): ?>
                    <div><span class="k">Why</span><span class="v"><?= esc($f['Routing Reason']) ?></span></div>
                <?php endif ?>
                <div><span class="k">Category</span><span class="v"><?= esc($f['Category'] ?? 'Not set') ?></span></div>
                <div><span class="k">Assigned</span><span class="v"><?= $assignedTo ? esc($assignedTo['name']) : 'Unassigned' ?></span></div>
                <div><span class="k">Requester's department</span><span class="v"><?= esc($f['Requester Department'] ?? '') ?: 'Not given' ?></span></div>
                <?php if (! empty($f['Service Key'])): ?>
                    <div><span class="k">Request type</span><span class="v"><?= esc($f['Service Key']) ?></span></div>
                <?php endif ?>
            </div>
        </div>

        <h3 class="section-title" id="conversation"><?= icon('mail', 16) ?> Conversation</h3>

        <div class="thread" id="thread" data-ticket="<?= esc($tid) ?>">
            <?php if (empty($messages)): ?>
                <div class="thread-empty">No messages yet — start the conversation below.</div>
            <?php endif ?>
            <?php foreach ($messages as $msg): ?>
                <?= render_message($msg) ?>
            <?php endforeach ?>
        </div>

        <div class="msg role-ai msg-thinking" id="msg-thinking" hidden>
            <div class="msg-avatar" aria-hidden="true"><?= icon('sparkle', 18) ?></div>
            <div class="msg-body">
                <div class="msg-meta">
                    <span class="msg-author">HelpDesk Assistant</span>
                    <span class="badge-ai"><?= icon('sparkle', 11) ?> AI</span>
                </div>
                <div class="msg-text typing" role="status" aria-live="polite">
                    <span class="dots"><i></i><i></i><i></i></span>
                    <span class="typing-label">Reading your message…</span>
                </div>
            </div>
        </div>

        <?php if (in_array((string) ($f['Status'] ?? 'New'), ['Closed', 'Cancelled'], true)): ?>
            <div class="thread-empty">This ticket is <?= esc(strtolower((string) $f['Status'])) ?>. Raise a new one if it comes back.</div>
            <?php if (! empty($feedback) && (int) $feedback['rating'] > 0): ?>
                <div class="field-hint">Rated <?= (int) $feedback['rating'] ?> out of 5 by the requester.</div>
            <?php endif ?>
        <?php else: ?>
            <form action="/tickets/<?= esc($tid) ?>/messages" method="post" class="composer" id="composer">
                <?= csrf_field() ?>
                <textarea name="body" id="composer-body" rows="3" placeholder="Write a reply…  Type @ to mention someone" required autocomplete="off"></textarea>
                <div class="mention-pop" id="mention-pop" hidden></div>
                <div class="composer-foot">
                    <?php if ($canManage): ?>
                        <label class="solution-toggle internal-toggle" title="Only staff can see internal notes">
                            <input type="checkbox" name="is_internal" value="1" id="is-internal">
                            <span><?= icon('lock', 13) ?> Internal note</span>
                        </label>
                        <label class="solution-toggle" title="Only replies marked here are remembered">
                            <input type="checkbox" name="is_solution" value="1">
                            <span><?= icon('check', 13) ?> This reply is the solution</span>
                        </label>
                        <?php if (! empty($canned)): ?>
                            <select class="canned-pick" id="canned-pick" aria-label="Insert a canned reply">
                                <option value="">Canned reply...</option>
                                <?php foreach ($canned as $c): ?>
                                    <option value="<?= esc($c['body']) ?>"><?= esc($c['title']) ?></option>
                                <?php endforeach ?>
                            </select>
                        <?php endif ?>
                    <?php else: ?>
                        <span class="composer-hint">
                            The assistant reads your replies. Say it's sorted and it'll mark the ticket resolved.
                        </span>
                    <?php endif ?>
                    <button type="submit" class="btn btn-sm"><?= icon('send', 15) ?> Send</button>
                </div>
                <?php if ($canManage): ?>
                    <div class="composer-hint">Tick that box and this answer is reused on similar tickets later. Leave it unticked and nothing is remembered.</div>
                <?php endif ?>
            </form>
        <?php endif ?>

        <h3 class="section-title"><?= icon('inbox', 16) ?> Request detail</h3>
        <div class="detail-grid">
            <div class="detail-field full">
                <div class="field-label">Description <span class="badge-ai sm"><?= icon('sparkle', 10) ?> AI</span></div>
                <div class="field-value"><?= esc($f['Description'] ?? $f['Request'] ?? '—') ?></div>
            </div>
            <div class="detail-field full">
                <div class="field-label">
                    In the requester's own words
                    <?php if (! empty($f['Request HTML'])): ?>
                        <span class="badge-outline">formatted</span>
                    <?php endif ?>
                </div>
                <?php if (! empty($f['Request HTML'])): ?>
                    <div class="rich-body"><?= (new \App\Libraries\Html())->clean((string) $f['Request HTML']) ?></div>
                <?php else: ?>
                    <div class="field-value"><?= esc($f['Request'] ?? '—') ?></div>
                <?php endif ?>
            </div>
            <?php foreach ($answers as $label => $value): ?>
                <div class="detail-field">
                    <div class="field-label"><?= esc($label) ?></div>
                    <div class="field-value"><?= esc($value) ?></div>
                </div>
            <?php endforeach ?>
            <div class="detail-field full">
                <div class="field-label">Expected deliverable</div>
                <div class="field-value"><?= esc($f['Expected Deliverable'] ?? '—') ?></div>
            </div>
            <?php if (trim((string) ($f['Requirements Needed'] ?? '')) !== '' && stripos((string) $f['Requirements Needed'], 'to be confirmed') === false): ?>
                <div class="detail-field full">
                    <div class="field-label">Requirements needed</div>
                    <div class="field-value"><?= esc($f['Requirements Needed']) ?></div>
                </div>
            <?php endif ?>
            <div class="detail-field full">
                <div class="field-label">Closure criteria</div>
                <div class="field-value"><?= esc($f['Closure Criteria'] ?? '—') ?></div>
            </div>
            <?php if (! empty($f['Resolution Note'])): ?>
                <div class="detail-field full">
                    <div class="field-label">Resolution note</div>
                    <div class="field-value"><?= esc($f['Resolution Note']) ?></div>
                </div>
            <?php endif ?>
            <?php if (! empty($f['AI Resolution Note'])): ?>
                <div class="detail-field full">
                    <div class="field-label">Resolution note <span class="badge-ai sm"><?= icon('sparkle', 10) ?> AI</span></div>
                    <div class="field-value"><?= esc($f['AI Resolution Note']) ?></div>
                </div>
            <?php endif ?>
        </div>

        <?php if ($isStaffView && ! empty($events)): ?>
            <h3 class="section-title"><?= icon('clock', 16) ?> Activity</h3>
            <ol class="activity-log">
                <?php foreach ($events as $e): ?>
                    <li><span class="when"><?= esc(format_when($e['created_at'])) ?></span> <strong><?= esc($e['actor_name'] ?: 'System') ?></strong> <?= esc(\App\Libraries\Activity::describe($e)) ?></li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>
    </div>

    <div>
        <?php if ($canManage): ?>
            <div class="side-panel">
                <h3><?= icon('edit', 15) ?> Update status</h3>
                <form action="/tickets/<?= esc($tid) ?>/status" method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <select name="status" id="status-pick">
                        <?php foreach (\App\Models\TicketModel::STATUSES as $opt): ?>
                            <option value="<?= $opt ?>" <?= ($f['Status'] ?? 'New') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach ?>
                    </select>
                    <div id="resolve-note" hidden>
                        <label for="resolution_note">What fixed it?</label>
                        <textarea id="resolution_note" name="resolution_note" rows="3" maxlength="2000" placeholder="A short note the requester and the team will see"></textarea>
                        <div class="field-hint">Needs an assignee and a note to resolve.</div>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm"><?= icon('check', 14) ?> Update</button>
                </form>
            </div>

            <div class="side-panel">
                <h3><?= icon('flag', 15) ?> Priority &amp; assignment</h3>
                <form action="/tickets/<?= esc($tid) ?>/meta" method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <?php foreach (priorities() as $opt): ?>
                            <option value="<?= $opt ?>" <?= ($meta['priority'] ?? 'Medium') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach ?>
                    </select>

                    <label for="due_date">Due date <span class="by-user">set from the SLA, change only if agreed</span></label>
                    <input type="date" id="due_date" name="due_date" value="<?= esc($meta['due_date'] ?? '') ?>">

                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <?php foreach (ticket_categories() as $opt): ?>
                            <option value="<?= esc($opt) ?>" <?= ($f['Category'] ?? '') === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                        <?php endforeach ?>
                    </select>


                    <label for="owning_team">Owning team</label>
                    <select id="owning_team" name="owning_team">
                        <?php
                            $ownerListed = array_filter($teams, static fn ($t) => strcasecmp($t['department'], $owner['department']) === 0
                                && strcasecmp($t['role'], $owner['role']) === 0) !== [];
                        ?>
                        <?php if (! $ownerListed): ?>
                            <option value="<?= esc($owner['department'] . '||' . $owner['role']) ?>" selected><?= esc($ownerLabel) ?> (current)</option>
                        <?php endif ?>
                        <?php foreach ($teams as $team): ?>
                            <?php $isOwner = strcasecmp($team['department'], $owner['department']) === 0 && strcasecmp($team['role'], $owner['role']) === 0; ?>
                            <option value="<?= esc($team['department'] . '||' . $team['role']) ?>" <?= $isOwner ? 'selected' : '' ?>><?= esc(\App\Libraries\RoutingDirectory::label($team)) ?></option>
                        <?php endforeach ?>
                    </select>

                    <label for="assigned_to">Assign to</label>
                    <?php
                        $holderId     = (int) ($meta['assigned_to'] ?? 0);
                        $holderListed = in_array($holderId, array_map('intval', array_column($staff, 'id')), true);
                    ?>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">Unassigned</option>
                        <?php if ($holderId > 0 && ! $holderListed && $assignedTo): ?>
                            <option value="<?= $holderId ?>" selected><?= esc($assignedTo['name']) ?> (current)</option>
                        <?php endif ?>
                        <?php foreach ($staff as $person): ?>
                            <option value="<?= (int) $person['id'] ?>" <?= $holderId === (int) $person['id'] ? 'selected' : '' ?>>
                                <?= esc($person['name']) ?><?= $person['role'] !== 'agent' ? ' (' . esc(role_label((string) $person['role'])) . ')' : '' ?>
                            </option>
                        <?php endforeach ?>
                    </select>

                    <button type="submit" class="btn btn-secondary"><?= icon('check', 15) ?> Save</button>
                </form>
                <?php if (empty($staff)): ?>
                    <div class="field-hint">No staff are set up in <strong><?= esc(resolve_base_department(ticket_department($f))) ?></strong> yet.</div>
                <?php endif ?>
            </div>
        <?php else: ?>
            <div class="side-panel">
                <h3><?= icon('clock', 15) ?> Where it stands</h3>
                <div class="stand-list">
                    <div><span class="k">Status</span><span class="v"><?= esc($f['Status'] ?? 'New') ?></span></div>
                    <div><span class="k">Priority</span><span class="v"><?= esc($meta['priority'] ?? 'Medium') ?></span></div>
                    <div><span class="k">Owning team</span><span class="v"><?= esc($ownerLabel) ?></span></div>
                    <div><span class="k">Target</span><span class="v"><?= esc(format_when($meta['due_at'] ?? null) ?: 'Not set') ?></span></div>
                </div>
                <div class="field-hint">Anything you add in the conversation is visible to the team handling this.</div>
            </div>
        <?php endif ?>
    </div>
</div>

<script>
(function () {
    var pick = document.getElementById('status-pick');
    var note = document.getElementById('resolve-note');
    if (pick && note) {
        var sync = function () { note.hidden = pick.value !== 'Resolved'; };
        pick.addEventListener('change', sync);
        sync();
    }
    var canned = document.getElementById('canned-pick');
    var body   = document.getElementById('composer-body');
    if (canned && body) {
        canned.addEventListener('change', function () {
            if (!canned.value) return;
            body.value = (body.value ? body.value + '\n\n' : '') + canned.value;
            canned.value = '';
            body.focus();
        });
    }
    var internal = document.getElementById('is-internal');
    if (internal && body) {
        internal.addEventListener('change', function () {
            body.classList.toggle('is-internal', internal.checked);
            body.placeholder = internal.checked ? 'Internal note, only staff will see this' : 'Write a reply…  Type @ to mention someone';
        });
    }
})();
</script>

<script>
(function () {
    var thread   = document.getElementById('thread');
    var thinking = document.getElementById('msg-thinking');
    var composer = document.getElementById('composer');
    if (!thread) return;

    if (composer) {
        composer.addEventListener('submit', function () {
            var btn = composer.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
        });
    }

    var box = document.getElementById('composer-body');
    var pop = document.getElementById('mention-pop');
    if (box && pop) {
        var items = [], active = -1, anchor = -1, timer = null;

        function hide() { pop.hidden = true; items = []; active = -1; anchor = -1; }

        function draw() {
            if (!items.length) { hide(); return; }
            pop.innerHTML = items.map(function (u, i) {
                return '<button type="button" class="mention-item' + (i === active ? ' is-active' : '') + '" data-i="' + i + '">'
                    + '<span class="mention-name"></span><span class="mention-meta"></span></button>';
            }).join('');
            Array.prototype.forEach.call(pop.querySelectorAll('.mention-item'), function (el, i) {
                el.querySelector('.mention-name').textContent = items[i].name;
                el.querySelector('.mention-meta').textContent = items[i].role + (items[i].department ? ' · ' + items[i].department : '');
                el.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
            });
            pop.hidden = false;
        }

        function pick(i) {
            var u = items[i];
            if (!u) return;
            var before = box.value.slice(0, anchor);
            var after  = box.value.slice(box.selectionStart);
            box.value = before + '@' + u.name + ' ' + after;
            var at = (before + '@' + u.name + ' ').length;
            box.focus();
            box.setSelectionRange(at, at);
            hide();
        }

        function look() {
            var upto = box.value.slice(0, box.selectionStart);
            var m = /@([\p{L}\p{N}._'-]{0,30})$/u.exec(upto);
            if (!m) { hide(); return; }
            anchor = box.selectionStart - m[0].length;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fetch('/notifications/mentionable?ticket=' + encodeURIComponent(thread.getAttribute('data-ticket'))
                    + '&q=' + encodeURIComponent(m[1]), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { items = Array.isArray(data) ? data : []; active = items.length ? 0 : -1; draw(); })
                    .catch(hide);
            }, 140);
        }

        box.addEventListener('input', look);
        box.addEventListener('blur', function () { setTimeout(hide, 120); });
        box.addEventListener('keydown', function (e) {
            if (pop.hidden || !items.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % items.length; draw(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % items.length; draw(); }
            else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pick(active); }
            else if (e.key === 'Escape') { hide(); }
        });
    }
    var ticket = thread.getAttribute('data-ticket');

    function lastMessageId() {
        var ids = Array.prototype.map.call(thread.querySelectorAll('[data-message-id]'), function (el) {
            return parseInt(el.getAttribute('data-message-id'), 10) || 0;
        });
        return ids.length ? Math.max.apply(null, ids) : 0;
    }

    function awaitReply(after, onReply, onGiveUp) {
        var tries = 0;
        (function poll() {
            fetch('/tickets/' + encodeURIComponent(ticket) + '/updates?after=' + after, {
                credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var replies = ((data && data.messages) || []).filter(function (m) { return m.role === 'ai'; });
                    if (replies.length) { onReply(replies); return; }
                    if (++tries < 72) { setTimeout(poll, 5000); } else { onGiveUp(); }
                })
                .catch(function () {
                    if (++tries < 72) { setTimeout(poll, 5000); } else { onGiveUp(); }
                });
        })();
    }

    var awaitingAi = <?= ! empty($awaitingAi) ? 'true' : 'false' ?>;
    if (awaitingAi && thinking) {
        thinking.hidden = false;
        var cLabel = thinking.querySelector('.typing-label');
        if (cLabel) cLabel.textContent = 'Reading your ticket…';
        var cStages = [
            [12000, 'Classifying and routing…'],
            [40000, 'Still going — the local model may be warming up.'],
            [120000, 'Taking a while — the first ticket after a quiet spell can take a few minutes. Your ticket is saved either way.']
        ];
        var cTimers = cStages.map(function (st) {
            return setTimeout(function () { if (cLabel) cLabel.textContent = st[1]; }, st[0]);
        });

        var cForm = new FormData();
        cForm.append(<?= json_encode(csrf_token()) ?>, <?= json_encode(csrf_hash()) ?>);
        var cBefore = lastMessageId();

        fetch('/tickets/' + encodeURIComponent(ticket) + '/classify', {
            method: 'POST', body: cForm, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                cTimers.forEach(clearTimeout);
                thinking.remove();
                if (data && data.changed) { window.location.reload(); return; }
                if (data && data.message) {
                    var placeholder = thread.querySelector('.thread-empty');
                    if (placeholder) placeholder.remove();
                    thread.insertAdjacentHTML('beforeend', data.message);
                }
            })
            .catch(function () {
                cTimers.forEach(clearTimeout);
                if (cLabel) cLabel.textContent = 'Still working — the first ticket after a quiet spell can take a few minutes. Your ticket is saved either way.';
                awaitReply(cBefore, function () { window.location.reload(); }, function () {
                    thinking.remove();
                    thread.insertAdjacentHTML('beforeend',
                        '<div class="thread-empty">The assistant could not be reached. Your ticket is saved and the team can see it.</div>');
                });
            });
    }

    var pending = <?= $assistMessageId > 0 ? $assistMessageId : 'null' ?>;
    if (!pending || !thinking) return;

    var empty  = thread.querySelector('.thread-empty');
    if (empty) empty.remove();

    thinking.hidden = false;
    thinking.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

    var label = thinking.querySelector('.typing-label');
    var stages = [
        [12000, 'Working through the ticket…'],
        [35000, 'Still going — the local model may be warming up.'],
        [90000, 'Taking a while. Your message is saved either way.']
    ];
    var timers = stages.map(function (s) {
        return setTimeout(function () { if (label) label.textContent = s[1]; }, s[0]);
    });

    function done() { timers.forEach(clearTimeout); thinking.remove(); }

    var before = lastMessageId();
    var form = new FormData();
    form.append('message_id', pending);

    form.append(<?= json_encode(csrf_token()) ?>, <?= json_encode(csrf_hash()) ?>);

    fetch('/tickets/' + encodeURIComponent(ticket) + '/assist', {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            done();
            if (!data || !data.ok || !data.message) return;

            thread.insertAdjacentHTML('beforeend', data.message);
            var added = thread.lastElementChild;
            if (added) added.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

            if (data.changed && data.changed.length) {
                setTimeout(function () { window.location.reload(); }, 2500);
            }
        })
        .catch(function () {
            timers.forEach(clearTimeout);
            if (label) label.textContent = 'Still working — this one is taking a while. Your message is saved either way.';

            awaitReply(before, function (replies) {
                done();
                replies.forEach(function (m) { thread.insertAdjacentHTML('beforeend', m.html); });
                var added = thread.lastElementChild;
                if (added) added.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }, function () {
                done();
                thread.insertAdjacentHTML('beforeend',
                    '<div class="thread-empty">The assistant could not be reached. Your message is saved and the team can see it.</div>');
            });
        });
})();
</script>

<?= $this->endSection() ?>
