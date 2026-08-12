<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email — Chikiting System</title>
    <?= theme_head() ?>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="auth-shell">
        <?= theme_toggle('auth-theme') ?>
        <div class="auth-wrap">
            <div class="auth-brand"><?= brand_mark(34) ?> Chikiting System</div>

            <div class="auth-card">
                <div class="step-rail" aria-label="Step 2 of 2">
                    <span class="step is-done"><b><?= icon('check', 11) ?></b> Your details</span>
                    <span class="step-rule is-done"></span>
                    <span class="step is-current"><b>2</b> Verify email</span>
                </div>

                <h1>Check your inbox</h1>
                <div class="subtitle">
                    We sent a <?= (int) $codeLength ?>-digit code to <strong><?= esc($email) ?></strong>.
                    It expires in <?= (int) $ttlMinutes ?> minutes.
                </div>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif ?>
                <?php if (session()->getFlashdata('success')): ?>
                    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
                <?php endif ?>

                <form action="/signup/verify" method="post" id="code-form">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="code-0">Verification code</label>
                        <div class="code-boxes" id="code-boxes">
                            <?php for ($i = 0; $i < (int) $codeLength; $i++): ?>
                                <input
                                    type="text"
                                    id="code-<?= $i ?>"
                                    name="code[]"
                                    class="code-box"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    maxlength="1"
                                    autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                                    aria-label="Digit <?= $i + 1 ?>"
                                    <?= $i === 0 ? 'autofocus' : '' ?>
                                    required>
                            <?php endfor ?>
                        </div>
                        <div class="field-hint">Paste the whole code into the first box if that's easier.</div>
                    </div>

                    <button type="submit" class="btn"><?= icon('check', 16) ?> Verify &amp; create account</button>
                </form>

                <form action="/signup/resend" method="post" class="resend-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-link" id="resend-btn"
                        <?= $cooldown > 0 ? 'disabled' : '' ?>
                        data-cooldown="<?= (int) $cooldown ?>"
                        data-label="Send a new code">
                        <?= $cooldown > 0 ? 'Send a new code (' . (int) $cooldown . 's)' : 'Send a new code' ?>
                    </button>
                </form>

                <div class="note-strip">
                    <?= icon('mail', 15) ?>
                    <span>Nothing arrived? Check junk, then confirm the address is spelt right — <a href="/signup">start over</a> if it isn't.</span>
                </div>

                <div class="auth-switch">Already verified? <a href="/login">Log in</a></div>
            </div>
        </div>
    </div>

    <?= theme_toggle_script() ?>
    <script>
    (function () {

        var boxes = Array.prototype.slice.call(document.querySelectorAll('.code-box'));

        function focusAt(i) {
            if (boxes[i]) { boxes[i].focus(); boxes[i].select(); }
        }

        function spread(text, from) {
            var digits = String(text).replace(/\D/g, '').split('');
            if (!digits.length) return false;
            for (var i = 0; i < digits.length && from + i < boxes.length; i++) {
                boxes[from + i].value = digits[i];
            }
            focusAt(Math.min(from + digits.length, boxes.length - 1));
            return true;
        }

        boxes.forEach(function (box, i) {
            box.addEventListener('input', function () {
                if (spread(box.value, i)) return;
                box.value = '';
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && box.value === '' && i > 0) {
                    e.preventDefault();
                    boxes[i - 1].value = '';
                    focusAt(i - 1);
                } else if (e.key === 'ArrowLeft' && i > 0) {
                    e.preventDefault(); focusAt(i - 1);
                } else if (e.key === 'ArrowRight' && i < boxes.length - 1) {
                    e.preventDefault(); focusAt(i + 1);
                }
            });

            box.addEventListener('paste', function (e) {
                e.preventDefault();
                spread((e.clipboardData || window.clipboardData).getData('text'), i);
            });

            box.addEventListener('focus', function () { box.select(); });
        });

        var btn = document.getElementById('resend-btn');
        var left = btn ? parseInt(btn.getAttribute('data-cooldown'), 10) || 0 : 0;
        if (btn && left > 0) {
            var label = btn.getAttribute('data-label');
            var tick = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(tick);
                    btn.disabled = false;
                    btn.textContent = label;
                } else {
                    btn.textContent = label + ' (' + left + 's)';
                }
            }, 1000);
        }
    })();
    </script>
</body>
</html>
