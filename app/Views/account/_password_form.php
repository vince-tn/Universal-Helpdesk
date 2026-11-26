<form action="/account/password" method="post" class="password-form">
    <?= csrf_field() ?>
    <div class="form-group">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    </div>
    <div class="form-grid">
        <div class="form-group">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="password" autocomplete="new-password" minlength="12" required>
        </div>
        <div class="form-group">
            <label for="new_password_confirm">Confirm new password</label>
            <input type="password" id="new_password_confirm" name="password_confirm" autocomplete="new-password" minlength="12" required>
        </div>
    </div>
    <div class="field-hint password-rules"><?= esc($pwRules) ?></div>
    <button type="submit" class="btn"><?= icon('lock', 16) ?> Change password</button>
</form>
