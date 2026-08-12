<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign up — Chikiting System</title>
    <?= theme_head() ?>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="auth-shell">
        <?= theme_toggle('auth-theme') ?>
        <div class="auth-wrap">
            <div class="auth-brand"><?= brand_mark(34) ?> Chikiting System</div>

            <div class="auth-card">
                <div class="step-rail" aria-label="Step 1 of 2">
                    <span class="step is-current"><b>1</b> Your details</span>
                    <span class="step-rule"></span>
                    <span class="step"><b>2</b> Verify email</span>
                </div>

                <h1>Create your account</h1>
                <div class="subtitle">Submit requests and track them in one place.</div>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-error"><?= icon('flag', 16) ?> <?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif ?>

                <form action="/signup" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="name">Full name</label>
                        <input type="text" id="name" name="name" value="<?= esc(old('name')) ?>" required autofocus autocomplete="name">
                    </div>
                    <div class="form-group">
                        <label for="email">Work email</label>
                        <input type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required autocomplete="email">
                        <div class="field-hint">
                            <?php if (! empty($domainCheck)): ?>
                                Must end in <?= esc($domainHint) ?>. We'll email a code to confirm it's yours.
                            <?php else: ?>
                                We'll email a one-time code to confirm this address is yours.
                            <?php endif ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                        <div class="field-hint">At least 8 characters.</div>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm password</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn"><?= icon('mail', 16) ?> Send me a code</button>
                </form>

                <?php if (! empty($domainCheck)): ?>
                    <div class="note-strip">
                        <?= icon('shield', 15) ?>
                        <span>External contractors and anyone on a different domain are set up by IT — raise it with them rather than signing up here.</span>
                    </div>
                <?php endif ?>

                <div class="auth-switch">Already have an account? <a href="/login">Log in</a></div>
            </div>
        </div>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
