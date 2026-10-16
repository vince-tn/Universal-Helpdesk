<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chikiting System</title>
    <?= theme_head() ?>
    <link rel="stylesheet" href="<?= esc(asset('css/style.css')) ?>">
</head>
<body>
    <?php $user = current_user(); ?>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="brand">
                <?= brand_mark() ?>
                Chikiting System
            </div>
            <div class="tagline">Support &amp; Ticketing</div>

            <nav>
                <a href="/tickets" class="<?= uri_string() === 'tickets' ? 'active' : '' ?>">
                    <?= icon('dashboard') ?>
                    <?= $user['role'] === 'requester' ? 'My Tickets' : 'Dashboard' ?>
                </a>
                <a href="/tickets/new" class="<?= uri_string() === 'tickets/new' ? 'active' : '' ?>">
                    <?= icon('plus') ?>
                    New Ticket
                </a>

                <?php $unread = unread_notifications(); ?>
                <a href="/notifications" class="<?= uri_string() === 'notifications' ? 'active' : '' ?>">
                    <?= icon('mail') ?>
                    Notifications
                    <?php if ($unread > 0): ?><span class="nav-badge"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif ?>
                </a>

                <?php if ($user['role'] === 'superadmin'): ?>
                    <div class="nav-section-label">Administration</div>
                    <a href="/reports" class="<?= uri_string() === 'reports' ? 'active' : '' ?>">
                        <?= icon('trend') ?>
                        Reports
                    </a>
                    <a href="/admin/users" class="<?= str_starts_with(uri_string(), 'admin/users') ? 'active' : '' ?>">
                        <?= icon('users') ?>
                        Manage Staff
                    </a>
                    <a href="/admin/access" class="<?= str_starts_with(uri_string(), 'admin/access') ? 'active' : '' ?>">
                        <?= icon('lock') ?>
                        Sign-up Access
                    </a>
                    <a href="/admin/departments" class="<?= str_starts_with(uri_string(), 'admin/departments') ? 'active' : '' ?>">
                        <?= icon('department') ?>
                        Departments
                    </a>
                <?php elseif (\App\Libraries\Org::canOpenAdmin($user)): ?>
                    <div class="nav-section-label">Your departments</div>
                    <a href="/admin/departments" class="<?= str_starts_with(uri_string(), 'admin/departments') ? 'active' : '' ?>">
                        <?= icon('department') ?>
                        Departments &amp; AI knowledge
                    </a>
                <?php else: ?>
                    <?php $knowledgeDept = \App\Libraries\Org::knowledgeOnlyDepartment($user); ?>
                    <?php if ($knowledgeDept !== null): ?>
                        <div class="nav-section-label">Your department</div>
                        <a href="/admin/departments/<?= (int) $knowledgeDept['id'] ?>/knowledge" class="<?= str_starts_with(uri_string(), 'admin/departments') ? 'active' : '' ?>">
                            <?= icon('inbox') ?>
                            AI knowledge
                        </a>
                    <?php endif ?>
                <?php endif ?>
            </nav>

            <div class="sidebar-spacer"></div>

            <div class="user-card">
                <div class="who">
                    <div class="user-avatar"><?= esc(strtoupper(substr($user['name'] ?? '?', 0, 1))) ?></div>
                    <div>
                        <div class="name"><?= esc($user['name'] ?? '') ?></div>
                        <div class="role"><?= esc($user['role'] ?? '') ?><?= $user['department'] ? ' · ' . esc($user['department']) : '' ?></div>
                    </div>
                </div>
                <?= theme_toggle() ?>

                <form class="logout-form" action="/logout" method="post">
                    <?= csrf_field() ?>
                    <button type="submit" class="logout-btn"><?= icon('logout') ?> Log out</button>
                </form>
            </div>
        </aside>

        <main class="main">
            <?= $this->renderSection('content') ?>
        </main>
    </div>
    <?= theme_toggle_script() ?>
</body>
</html>
