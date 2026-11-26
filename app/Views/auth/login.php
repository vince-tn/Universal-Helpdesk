<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | <?= esc(brand_name()) ?></title>
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
                <h1>Welcome back</h1>
                <div class="subtitle"><?= esc(brand_name()) ?></div>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif ?>
                <?php if (session()->getFlashdata('success')): ?>
                    <div class="alert alert-success"><?= icon('check', 16) ?> <?= esc(session()->getFlashdata('success')) ?></div>
                <?php endif ?>

                <form action="/login" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn"><?= icon('lock', 16) ?> Log in</button>
                </form>

                <?php if (! empty($ssoAvailable)): ?>
                    <div class="auth-divider"><span>or</span></div>
                    <a href="/sso/login" class="btn btn-secondary auth-sso"><?= icon('building', 16) ?> Sign in with Microsoft</a>
                <?php endif ?>

                <div class="auth-switch">New here? <a href="/signup">Create a requester account</a></div>
            </div>

        </div>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
