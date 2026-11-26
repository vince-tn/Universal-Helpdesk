<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | <?= esc(brand_name()) ?></title>
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
                <h1>Forgot your password?</h1>
                <div class="subtitle">Enter your email and we will send you a code to reset it.</div>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif ?>

                <form action="/forgot" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= esc(old('email')) ?>" autocomplete="email" required autofocus>
                    </div>
                    <button type="submit" class="btn"><?= icon('mail', 16) ?> Send code</button>
                </form>

                <div class="auth-switch">Remembered it? <a href="/login">Log in</a></div>
            </div>
        </div>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
