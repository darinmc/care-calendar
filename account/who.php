<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/permissions.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/account.php';
require __DIR__ . '/../includes/members.php';

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];

$members = get_account_members($accountId);
$invites = get_pending_invitations($accountId);

$pageTitle = "Who's in this calendar";
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="settings">

    <h1 class="settings__title">Who's in this calendar</h1>

    <p class="who-intro">
        This is <strong><?= e($account['subject_name']) ?></strong>'s calendar.
        The people below can see it and add to it. Everyone's name is shown next to anything they add.
    </p>

    <ul class="who-list">
        <?php foreach ($members as $m): ?>
            <?php
                $isMe = ((int)$m['user_id'] === $userId);
                $fullName = trim($m['first_name'] . ' ' . $m['last_name']);
                $initial  = mb_strtoupper(mb_substr($m['first_name'], 0, 1));
            ?>
            <li class="who-card<?= $m['role'] === 'subject' ? ' who-card--subject' : '' ?>">
                <span class="who-avatar" aria-hidden="true"><?= e($initial) ?></span>
                <span class="who-body">
                    <span class="who-name">
                        <?= e($fullName) ?>
                        <?php if ($isMe): ?><span class="who-badge">You</span><?php endif; ?>
                    </span>
                    <span class="who-role"><?= e(role_label($m['role'])) ?></span>
                    <span class="who-desc"><?= e(role_description($m['role'])) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (!empty($invites)): ?>
        <h2 class="who-section-title">Invited (not yet joined)</h2>
        <ul class="who-list">
            <?php foreach ($invites as $inv): ?>
                <li class="who-card who-card--pending">
                    <span class="who-avatar" aria-hidden="true">✉</span>
                    <span class="who-body">
                        <span class="who-name"><?= e($inv['email']) ?></span>
                        <span class="who-role"><?= e(role_label($inv['role'])) ?> · Invitation pending</span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="settings__footer">
        <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
           href="<?= e(base_url('account/settings.php')) ?>">Back to settings</a>
    </div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
