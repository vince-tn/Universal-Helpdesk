<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<div class="page-header">
    <div>
        <h1>Submit a New Ticket</h1>
        <div class="subtitle">Describe it in your own words — we'll classify it and route it to the right team.</div>
    </div>
</div>

<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<div class="form-card">
    <form action="/tickets" method="post" id="ticket-form">
        <?= csrf_field() ?>

        <div class="form-grid">
            <div class="form-group">
                <label for="requester_name">Requester Name</label>
                <input type="text" id="requester_name" value="<?= esc($user['name']) ?>" disabled>
            </div>

            <div class="form-group">
                <label for="requester_email">Requester Email</label>
                <input type="email" id="requester_email" value="<?= esc($user['email']) ?>" disabled>
            </div>

            <div class="form-group span-2">
                <label for="submitting_department">Submitting Department</label>
                <select id="submitting_department" name="submitting_department" required>
                    <option value="">Select a department...</option>
                    <?php foreach (departments() as $dept): ?>
                        <option value="<?= esc($dept) ?>" <?= old('submitting_department') === $dept ? 'selected' : '' ?>><?= esc($dept) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="form-group span-2">
                <label for="request_description">Describe your request</label>

                <textarea id="request_description" name="request_description" rows="7" required
                    placeholder="What do you need? Include what you've already tried and any deadline."><?= esc(old('request_description')) ?></textarea>

                <div class="composer-rich" id="rich" hidden>
                    <div class="rt-toolbar" role="toolbar" aria-label="Formatting">
                        <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
                        <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
                        <button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
                        <span class="rt-sep"></span>
                        <button type="button" data-cmd="insertUnorderedList" title="Bulleted list">&bull;&nbsp;List</button>
                        <button type="button" data-cmd="insertOrderedList" title="Numbered list">1.&nbsp;List</button>
                        <span class="rt-sep"></span>
                        <button type="button" data-cmd="createLink" title="Add a link"><?= icon('mail', 14) ?> Link</button>
                        <button type="button" id="rt-image" title="Attach a screenshot"><?= icon('download', 14) ?> Image</button>
                        <span class="rt-spacer"></span>
                        <span class="rt-hint">Paste screenshots straight in</span>
                    </div>

                    <div class="rt-surface" id="rt-surface" contenteditable="true" role="textbox" aria-multiline="true"
                         aria-label="Describe your request"
                         data-placeholder="What do you need? Include what you've already tried, any error message, and a deadline if there is one."></div>

                    <input type="file" id="rt-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                    <input type="hidden" name="request_html" id="request_html">
                </div>

                <div class="field-hint" id="rt-status">
                    Screenshots help a lot — paste one in, or drag it onto the box. JPG, PNG, GIF or WebP, up to 5MB each.
                </div>
            </div>
        </div>

        <button type="submit" class="btn" id="ticket-submit"><?= icon('send', 16) ?> Submit Ticket</button>
    </form>
</div>

<script>
(function () {
    var textarea = document.getElementById('request_description');
    var rich     = document.getElementById('rich');
    var surface  = document.getElementById('rt-surface');
    var hidden   = document.getElementById('request_html');
    var form     = document.getElementById('ticket-form');
    var status   = document.getElementById('rt-status');
    var picker   = document.getElementById('rt-file');
    if (!rich || !surface || !form) return;

    var CSRF_NAME = <?= json_encode(csrf_token()) ?>;
    var CSRF_HASH = <?= json_encode(csrf_hash()) ?>;
    var DEFAULT_HINT = status ? status.textContent : '';

    textarea.hidden = true;
    textarea.removeAttribute('required');
    rich.hidden = false;

    if (textarea.value.trim() !== '') {
        surface.textContent = textarea.value;
    }

    function setStatus(message, kind) {
        if (!status) return;
        status.textContent = message || DEFAULT_HINT;
        status.className = 'field-hint' + (kind ? ' is-' + kind : '');
    }

    rich.querySelectorAll('[data-cmd]').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
            var cmd = btn.getAttribute('data-cmd');
            surface.focus();
            if (cmd === 'createLink') {
                var url = window.prompt('Link to where?', 'https://');
                if (!url) return;
                document.execCommand('createLink', false, url);
                return;
            }
            document.execCommand(cmd, false, null);
        });
    });

    function upload(file) {
        if (!file || file.type.indexOf('image/') !== 0) return;

        if (file.size > 5 * 1024 * 1024) {
            setStatus('That image is larger than 5MB — please attach a smaller one.', 'error');
            return;
        }

        setStatus('Uploading ' + (file.name || 'image') + '…', 'busy');

        var body = new FormData();
        body.append('image', file);
        body.append(CSRF_NAME, CSRF_HASH);

        fetch('/tickets/attachments', {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    setStatus(data && data.error ? data.error : 'That image could not be uploaded.', 'error');
                    return;
                }
                surface.focus();

                var img = document.createElement('img');
                img.src = data.url;
                img.alt = file.name || 'attachment';
                insert(img);
                setStatus('');
            })
            .catch(function () {
                setStatus('That image could not be uploaded — check your connection and try again.', 'error');
            });
    }

    function insert(node) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || !surface.contains(sel.anchorNode)) {
            surface.appendChild(node);
        } else {
            var range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(node);
            range.setStartAfter(node);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        }
        surface.appendChild(document.createElement('br'));
    }

    if (picker) {
        document.getElementById('rt-image').addEventListener('click', function () { picker.click(); });
        picker.addEventListener('change', function () {
            Array.prototype.forEach.call(picker.files, upload);
            picker.value = '';
        });
    }

    surface.addEventListener('paste', function (e) {
        var items = (e.clipboardData || window.clipboardData).items || [];
        var handled = false;

        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image/') === 0) {
                var file = items[i].getAsFile();
                if (file) { upload(file); handled = true; }
            }
        }

        if (handled) { e.preventDefault(); return; }

        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
    });

    ['dragenter', 'dragover'].forEach(function (type) {
        surface.addEventListener(type, function (e) { e.preventDefault(); surface.classList.add('is-dropping'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        surface.addEventListener(type, function (e) { e.preventDefault(); surface.classList.remove('is-dropping'); });
    });
    surface.addEventListener('drop', function (e) {
        var files = (e.dataTransfer || {}).files || [];
        Array.prototype.forEach.call(files, upload);
    });

    form.addEventListener('submit', function (e) {
        hidden.value = surface.innerHTML;

        var plain = (surface.innerText || '').replace(/\s+/g, ' ').trim();
        var hasImage = surface.querySelector('img') !== null;

        if (plain.length < 10 && !hasImage) {
            e.preventDefault();
            setStatus('Please describe your request in a bit more detail — at least a sentence.', 'error');
            surface.focus();
            return;
        }

        textarea.value = plain;

        var btn = document.getElementById('ticket-submit');
        if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
    });
})();
</script>

<?= $this->endSection() ?>
