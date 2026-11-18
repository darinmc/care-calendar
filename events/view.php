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
require __DIR__ . '/../includes/events.php';

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];

$eventId = (int)($_GET['id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(400);
    exit('Missing event id.');
}

$event = get_event($eventId, $userId, $accountId);
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$tz = new DateTimeZone($account['timezone']);
$startLocal = (new DateTimeImmutable($event['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);
$endLocal   = null;
if (!empty($event['end_at'])) {
    $endLocal = (new DateTimeImmutable($event['end_at'], new DateTimeZone('UTC')))->setTimezone($tz);
}

$isDeleted = ($event['status'] === 'deleted');

// Determine "past" for editing restrictions
$todayYmd     = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$eventDateYmd = substr($event['start_at'], 0, 10);  // stored in UTC
$eventIsPast  = ($eventDateYmd < $todayYmd);

$role           = member_role($userId, $accountId);
$isOwnerOrAdmin = in_array($role, ['owner', 'admin'], true);

// Everyone can edit/delete per their role on today/future days.
// On past days, only owner/admin can edit/delete.
$canEdit = can_edit_event_fields($userId, $accountId)
           && (!$eventIsPast || $isOwnerOrAdmin);

$pageTitle = $event['title'];
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="event">

    <h1 class="event__title"><?= e($event['title']) ?></h1>

    <?php if ($isDeleted): ?>
        <div class="flash flash--warning" style="margin-bottom:20px;">
            This event was deleted
            <?php if (!empty($event['delete_reason'])): ?>
                — reason: <em><?= e($event['delete_reason']) ?></em>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p class="event__meta">
        <span class="event__label">When:</span>
        <?php if ($event['all_day']): ?>
            All day · <?= e($startLocal->format('l j F Y')) ?>
        <?php else: ?>
            <?= e($startLocal->format('l j F Y, g:i a')) ?>
            <?php if ($endLocal): ?>
                – <?= e($endLocal->format('g:i a')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </p>

    <?php if (!empty($event['location'])): ?>
        <p class="event__meta"><span class="event__label">Where:</span> <?= e($event['location']) ?></p>
    <?php endif; ?>

    <p class="event__meta">
        <span class="event__label">Added by:</span>
        <?= e($event['creator_first'] ?? 'Someone') ?>
        <?= e($event['creator_last'] ?? '') ?>
    </p>

    <?php if (!empty($event['description'])): ?>
        <div class="event__section">
            <h2>Notes</h2>
            <p class="event__desc"><?= e($event['description']) ?></p>
        </div>
    <?php endif; ?>

        <div class="event__actions">
        <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
           href="<?= e(base_url('calendar/index.php?date=' . $startLocal->format('Y-m-d'))) ?>">
            Back to day
        </a>
        <?php if ($canEdit && !$isDeleted): ?>
            <a class="btn" href="<?= e(base_url('events/edit.php?id=' . $eventId)) ?>">Edit</a>
            <a class="btn btn--danger" href="<?= e(base_url('events/delete.php?id=' . $eventId)) ?>">Delete</a>
        <?php endif; ?>
        <?php if (can_view_history($userId, $accountId)): ?>
            <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
               href="<?= e(base_url('events/history.php?id=' . $eventId)) ?>">History</a>
        <?php endif; ?>
    </div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
