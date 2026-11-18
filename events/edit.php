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
    flash_set('error', 'That event has been deleted. Restore it first if you need to edit it.');
    header('Location: ' . base_url('events/view.php?id=' . $eventId));
    exit;
}

if (!can_edit_event_fields($userId, $accountId)) {
    http_response_code(403);
    exit('You do not have permission to edit events.');
}

// Past-day restriction: only owner/admin can edit past events
$todayYmd     = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$eventDateYmd = substr($event['start_at'], 0, 10);
$eventIsPast  = ($eventDateYmd < $todayYmd);
$role           = member_role($userId, $accountId);
$isOwnerOrAdmin = in_array($role, ['owner', 'admin'], true);

if ($eventIsPast && !$isOwnerOrAdmin) {
    flash_set('error', 'This event is in the past. Only an owner or admin can edit it.');
    header('Location: ' . base_url('events/view.php?id=' . $eventId));
    exit;
}

$tz = new DateTimeZone($account['timezone']);

$startLocal = (new DateTimeImmutable($event['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);
$endLocal   = null;
if (!empty($event['end_at'])) {
    $endLocal = (new DateTimeImmutable($event['end_at'], new DateTimeZone('UTC')))->setTimezone($tz);
}

$errors = [];
$form = [
    'title'       => $event['title'],
    'description' => (string)($event['description'] ?? ''),
    'location'    => (string)($event['location'] ?? ''),
    'date'        => $startLocal->format('Y-m-d'),
    'start_time'  => $startLocal->format('H:i'),
    'end_time'    => $endLocal ? $endLocal->format('H:i') : '',
    'all_day'     => (bool)$event['all_day'],
    'category'    => $event['category'],
    'visibility'  => $event['visibility'],
    'reason'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_request();

    $form['title']       = trim((string)($_POST['title'] ?? ''));
    $form['description'] = trim((string)($_POST['description'] ?? ''));
    $form['location']    = trim((string)($_POST['location'] ?? ''));
    $form['date']        = trim((string)($_POST['date'] ?? ''));
    $form['start_time']  = trim((string)($_POST['start_time'] ?? ''));
    $form['end_time']    = trim((string)($_POST['end_time'] ?? ''));
    $form['all_day']     = !empty($_POST['all_day']);
    $form['category']    = (string)($_POST['category'] ?? 'appointment');
    $form['visibility']  = (string)($_POST['visibility'] ?? 'all');
    $form['reason']      = trim((string)($_POST['reason'] ?? ''));

    $utc = new DateTimeZone('UTC');

$startAt = (new DateTimeImmutable($form['date'] . ' ' . ($form['start_time'] ?: '00:00') . ':00', $tz))
    ->setTimezone($utc)->format('Y-m-d H:i:s');

$endAt = (!empty($form['end_time']))
    ? (new DateTimeImmutable($form['date'] . ' ' . $form['end_time'] . ':00', $tz))
        ->setTimezone($utc)->format('Y-m-d H:i:s')
    : null;

if ($form['all_day']) {
    $startAt = (new DateTimeImmutable($form['date'] . ' 00:00:00', $tz))
        ->setTimezone($utc)->format('Y-m-d H:i:s');
    $endAt = null;
}

    $needsReason = should_require_reason(
        [
            'title'      => $event['title'],
            'visibility' => $event['visibility'],
            'start_at'   => $event['start_at'],
        ],
        [
            'title'      => $form['title'],
            'visibility' => $form['visibility'],
            'start_at'   => $startAt,
        ],
        'update'
    );

    if ($needsReason && $form['reason'] === '') {
        $errors[] = 'This change needs a reason. Please add one below.';
    }

    if (empty($errors)) {
        try {
            update_event(
                $eventId,
                $accountId,
                $userId,
                [
                    'title'       => $form['title'],
                    'description' => $form['description'],
                    'location'    => $form['location'],
                    'start_at'    => $startAt,
                    'end_at'      => $endAt,
                    'all_day'     => $form['all_day'],
                    'category'    => $form['category'],
                    'visibility'  => $form['visibility'],
                ],
                $form['reason'] !== '' ? $form['reason'] : null
            );

            flash_set('success', 'Event updated.');
            header('Location: ' . base_url('events/view.php?id=' . $eventId));
            exit;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('update_event failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Edit: ' . $event['title'];
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="form form--wide">
    <h1 class="form__title">Edit event</h1>

    <?php foreach ($errors as $err): ?>
        <p class="form__error" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$eventId ?>">

        <div class="form__row">
            <label class="form__label" for="title">What's happening?</label>
            <input class="form__input" type="text" id="title" name="title"
                   value="<?= e($form['title']) ?>" required autofocus>
        </div>

        <div class="form__row">
            <label class="form__label" for="date">Date</label>
            <input class="form__input" type="date" id="date" name="date"
                   value="<?= e($form['date']) ?>" required>
        </div>

        <div class="form__row form__check">
            <input type="checkbox" id="all_day" name="all_day" value="1"
                   <?= $form['all_day'] ? 'checked' : '' ?>>
            <label for="all_day">All day</label>
        </div>

        <div class="form__row form__row--half">
            <div>
                <label class="form__label" for="start_time">Starts</label>
                <input class="form__input" type="time" id="start_time" name="start_time"
                       value="<?= e($form['start_time']) ?>">
            </div>
            <div>
                <label class="form__label" for="end_time">Ends <span class="form__help" style="display:inline;">(optional)</span></label>
                <input class="form__input" type="time" id="end_time" name="end_time"
                       value="<?= e($form['end_time']) ?>">
            </div>
        </div>

        <div class="form__row">
            <label class="form__label" for="description">Notes <span class="form__help" style="display:inline;">(optional)</span></label>
            <textarea class="form__textarea" id="description" name="description"><?= e($form['description']) ?></textarea>
        </div>

        <details class="form__row" style="margin-top:24px;" <?= ($form['location'] || $form['category'] !== 'appointment' || $form['visibility'] !== 'all') ? 'open' : '' ?>>
            <summary style="cursor:pointer; font-weight:600; font-size:var(--fs-lg); padding:8px 0;">
                More options
            </summary>

            <div style="margin-top:16px;">
                <div class="form__row">
                    <label class="form__label" for="location">Where?</label>
                    <input class="form__input" type="text" id="location" name="location"
                           value="<?= e($form['location']) ?>">
                </div>

                <div class="form__row form__row--half">
                    <div>
                        <label class="form__label" for="category">Category</label>
                        <select class="form__select" id="category" name="category">
                            <?php
                            $cats = [
                                'appointment' => '📅 Appointment',
                                'medication'  => '💊 Medication',
                                'social'      => '👥 Social',
                                'reminder'    => '🔔 Reminder',
                                'note'        => '📝 Note',
                                'other'       => '• Other',
                            ];
                            foreach ($cats as $val => $label):
                            ?>
                                <option value="<?= e($val) ?>" <?= $form['category'] === $val ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form__label" for="visibility">Who can see this?</label>
                        <select class="form__select" id="visibility" name="visibility">
                            <option value="all"     <?= $form['visibility'] === 'all'     ? 'selected' : '' ?>>Everyone in the family</option>
                            <option value="editors" <?= $form['visibility'] === 'editors' ? 'selected' : '' ?>>Family members only</option>
                            <option value="owner"   <?= $form['visibility'] === 'owner'   ? 'selected' : '' ?>>Only me and admins</option>
                        </select>
                    </div>
                </div>
            </div>
        </details>

        <div class="form__row" style="margin-top:24px;">
            <label class="form__label" for="reason">Reason for change <span class="form__help" style="display:inline;">(required if you change the title, time, or visibility)</span></label>
            <input class="form__input" type="text" id="reason" name="reason"
                   value="<?= e($form['reason']) ?>"
                   placeholder="e.g. GP rang, running late">
        </div>

        <div class="form__actions">
            <button type="submit" class="btn">Save changes</button>
            <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
               href="<?= e(base_url('events/view.php?id=' . $eventId)) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
