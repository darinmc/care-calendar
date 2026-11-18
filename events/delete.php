<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/permissions.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/account.php';
require __DIR__ . '/../includes/events.php';

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];

$eventId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(400);
    exit('Missing event id.');
}

$event = get_event($eventId, $userId, $accountId);
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

if ($event['status'] === 'deleted') {
    flash_set('info', 'That event is already deleted.');
    header('Location: ' . base_url('events/view.php?id=' . $eventId));
    exit;
}

$isOwn = ((int)$event['created_by'] === $userId);
if (!$isOwn && !can_delete_others_event($userId, $accountId)) {
    http_response_code(403);
    exit('You can only delete events you created.');
}

// Past-day restriction: only owner/admin can delete past events
$todayYmd     = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$eventDateYmd = substr($event['start_at'], 0, 10);
$eventIsPast  = ($eventDateYmd < $todayYmd);
$role           = member_role($userId, $accountId);
$isOwnerOrAdmin = in_array($role, ['owner', 'admin'], true);

if ($eventIsPast && !$isOwnerOrAdmin) {
    flash_set('error', 'This event is in the past. Only an owner or admin can delete it.');
    header('Location: ' . base_url('events/view.php?id=' . $eventId));
    exit;
}

$tz = new DateTimeZone($account['timezone']);
$startLocal = (new DateTimeImmutable($event['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);

$errors = [];
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_request();
    $reason = trim((string)($_POST['reason'] ?? ''));

    $reasonError = validate_reason($reason);
    if ($reasonError) {
        $errors[] = $reasonError;
    }

    if (empty($errors)) {
        try {
            delete_event($eventId, $accountId, $userId, $reason);
            flash_set('success', 'Event deleted. You can restore it later if needed.');
            header('Location: ' . base_url('calendar/index.php?date=' . $startLocal->format('Y-m-d')));
            exit;
        } catch (Throwable $e) {
            error_log('delete_event failed: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Delete: ' . $event['title'];
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="form form--wide">
    <h1 class="form__title">Delete this event?</h1>

    <div class="flash flash--warning" style="margin-bottom:20px;">
        <strong><?= e($event['title']) ?></strong><br>
        <?= e($startLocal->format('l j F Y, g:i a')) ?>
    </div>

    <p style="font-size:var(--fs-lg); margin-bottom:20px;">
        Nothing is ever truly lost. This event will be hidden from the day view,
        and can be brought back later from the history. A short reason helps the family
        understand why it was removed.
    </p>

    <?php foreach ($errors as $err): ?>
        <p class="form__error" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$eventId ?>">

        <div class="form__row">
            <label class="form__label" for="reason">Why is this being deleted?</label>
            <input class="form__input" type="text" id="reason" name="reason"
                   value="<?= e($reason) ?>"
                   placeholder="e.g. Duplicate, wrong day, cancelled"
                   required autofocus>
            <span class="form__help">At least 5 characters. This will be visible in the event history.</span>
        </div>

        <div class="form__actions">
            <button type="submit" class="btn btn--danger">Delete event</button>
            <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
               href="<?= e(base_url('events/view.php?id=' . $eventId)) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
