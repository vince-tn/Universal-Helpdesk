<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-step sign-in | <?= esc(brand_name()) ?></title>
    <?= theme_head() ?>
    <link rel="stylesheet" href="<?= esc(asset('css/style.css')) ?>">
    <?= brand_css() ?>
</head>
<body>
    <div class="auth-shell">
        <?= theme_toggle('auth-theme') ?>
        <div class="auth-wrap">
            <div class="auth-brand"><?= brand_lockup(34) ?></div>

            <div class="auth-card">
                <h1>Two-step sign-in</h1>
                <div class="subtitle">Enter the 6-digit code from your authenticator app.</div>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif ?>

                <form action="/login/mfa" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="code">Code</label>
                        <input type="text" id="code" name="code" class="mfa-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus>
                        <div class="field-hint">Lost your phone? Enter one of your recovery codes instead, like abcde-12345.</div>
                    </div>
                    <button type="submit" class="btn"><?= icon('shield', 16) ?> Verify</button>
                </form>

                <div class="auth-switch"><a href="/login">Start over</a></div>
            </div>
        </div>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
