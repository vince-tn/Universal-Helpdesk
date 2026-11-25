<?= $this->extend('templates/layout') ?>

<?= $this->section('content') ?>

<?php if (! $required): ?>
    <a href="/account" class="back-link"><?= icon('chevron-left', 14) ?> Back to my settings</a>
<?php endif ?>

<div class="page-header">
    <div>
        <h1>Set up two-step sign-in</h1>
        <div class="subtitle">
            <?= $required
                ? 'Your role needs a code from your phone each time you sign in with a password.'
                : 'Add a code from your phone to each password sign in.' ?>
        </div>
    </div>
</div>

<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
<?php endif ?>

<?php if ($unavailable): ?>
    <div class="alert alert-error"><?= icon('flag', 16) ?> Two-step sign-in cannot be set up yet because the server has no encryption key.</div>
    <div class="form-card narrow">
        <p><strong>For the administrator:</strong> set <code>encryption.key</code> or <code>MFA_KEY</code> in the server .env, then restart the app. Secrets are never stored without one.</p>
        <div class="field-hint">Generate a key with <code>openssl rand -hex 32</code> and keep it with your other server secrets. Changing it later means everyone sets up again.</div>
    </div>
<?php else: ?>
    <div class="form-card mfa-setup">
        <div class="mfa-setup-grid">
            <div>
                <h2 class="section-title">1. Scan this code</h2>
                <p class="field-hint">Use Microsoft Authenticator, Google Authenticator or any app that supports time-based codes.</p>
                <div class="mfa-qr" id="mfa-qr" data-otpauth="<?= esc($otpauth, 'attr') ?>" role="img" aria-label="QR code for your authenticator app"></div>
                <div class="field-hint">Cannot scan? Add an account by hand with this key:</div>
                <div class="mfa-secret"><?= esc($secret) ?></div>
            </div>
            <div>
                <h2 class="section-title">2. Enter the code it shows</h2>
                <form action="/account/mfa/setup" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="code">6-digit code</label>
                        <input type="text" id="code" name="code" class="mfa-code-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
                    </div>
                    <button type="submit" class="btn"><?= icon('shield', 16) ?> Turn on two-step sign-in</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    (function () {
        var el = document.getElementById('mfa-qr');
        if (!el || typeof QRCode === 'undefined') return;
        new QRCode(el, { text: el.getAttribute('data-otpauth'), width: 192, height: 192, correctLevel: QRCode.CorrectLevel.M });
    })();
    </script>
<?php endif ?>

<?= $this->endSection() ?>
