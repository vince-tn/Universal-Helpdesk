<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New password | <?= esc(brand_name()) ?></title>
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
                <h1>Set a new password</h1>
                <div class="subtitle">Then log in with it.</div>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif ?>

                <form action="/forgot/new" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="password">New password</label>
                        <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm new password</label>
                        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="12" required>
                        <div class="field-hint"><?= esc($rules) ?></div>
                    </div>
                    <button type="submit" class="btn"><?= icon('lock', 16) ?> Save password</button>
                </form>
            </div>
        </div>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
