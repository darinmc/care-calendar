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
$role      = member_role($userId, $accountId);

$isOwnerOrAdmin = in_array($role, ['owner', 'admin'], true);
$isOwner        = ($role === 'owner');

$counts = count_account_members($accountId);

$whoLine = $counts['total'] === 1
    ? 'Just you'
    : ($counts['total'] . ' ' . ($counts['total'] === 1 ? 'person' : 'people'));

$tzLabel   = str_replace('_', ' ', $account['timezone']);
$weekLabel = ucfirst($account['week_starts_on'] ?? 'monday');

$pageTitle = 'Settings';
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="settings">

    <h1 class="settings__title">Settings</h1>

    <ul class="settings__list">

        <li>
            <a class="settings-card" href="<?= e(base_url('account/profile.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">👤</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Your profile</span>
                    <span class="settings-card__sub">
                        <?= e($user['first_name'] . ' ' . $user['last_name']) ?>
                        · <?= e($user['email']) ?>
                    </span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>

        <li>
            <a class="settings-card" href="<?= e(base_url('account/appearance.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">🎨</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Appearance</span>
                    <span class="settings-card__sub">Colour theme and brightness</span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>

        <?php if ($isOwnerOrAdmin): ?>
        <li>
            <a class="settings-card" href="<?= e(base_url('account/calendar-settings.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">🗓️</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Calendar settings</span>
                    <span class="settings-card__sub">
                        <?= e($account['display_name']) ?>
                        · <?= e($tzLabel) ?>
                        · Week starts <?= e($weekLabel) ?>
                    </span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($isOwnerOrAdmin): ?>
        <li>
            <a class="settings-card" href="<?= e(base_url('account/members.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">👥</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Family members</span>
                    <span class="settings-card__sub">
                        <?= e($whoLine) ?>
                        <?php if ($counts['invited'] > 0): ?>
                            · <?= (int)$counts['invited'] ?> invited
                        <?php endif; ?>
                    </span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>
        <?php endif; ?>

        <li>
            <a class="settings-card" href="<?= e(base_url('account/who.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">👥</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Who's in this calendar</span>
                    <span class="settings-card__sub"><?= e($whoLine) ?></span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>

        <?php if ($isOwnerOrAdmin): ?>
        <li>
            <a class="settings-card" href="<?= e(base_url('account/activity.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">📋</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Activity log</span>
                    <span class="settings-card__sub">Who did what, and when</span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($isOwner): ?>
        <li>
            <a class="settings-card" href="<?= e(base_url('account/billing.php')) ?>">
                <span class="settings-card__icon" aria-hidden="true">💳</span>
                <span class="settings-card__body">
                    <span class="settings-card__title">Billing</span>
                    <span class="settings-card__sub">Your plan and payment method</span>
                </span>
                <span class="settings-card__chevron" aria-hidden="true">›</span>
            </a>
        </li>
        <?php endif; ?>

    </ul>

    <div class="settings__footer">
        <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
           href="<?= e(base_url('calendar/index.php')) ?>">Back to calendar</a>
    </div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
