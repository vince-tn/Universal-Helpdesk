<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — Chikiting System</title>
    <?= theme_head() ?>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="auth-shell">
        <?= theme_toggle('auth-theme') ?>
        <div class="auth-wrap">
            <div class="auth-brand"><?= brand_mark(34) ?> Chikiting System</div>

            <div class="auth-card">
                <h1>Welcome back</h1>
                <div class="subtitle">Chikiting System</div>

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

                <div class="auth-switch">New here? <a href="/signup">Create a requester account</a></div>
            </div>

        </div>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
