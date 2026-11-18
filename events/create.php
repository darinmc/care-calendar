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
require __DIR__ . '/../includes/day_entries.php';

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];
$role      = member_role($userId, $accountId);

// Only these roles can add things
if (!can_create_events($userId, $accountId)) {
    http_response_code(403);
    exit('You do not have permission to add things.');
}

$tz = new DateTimeZone($account['timezone']);
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

// Date is frozen to the query string (?date=YYYY-MM-DD), default today.
// No date field on the form — foolproof.
$frozenDate = $_GET['date'] ?? $_POST['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $frozenDate)) {
    $frozenDate = $today;
}

$frozenDT = DateTimeImmutable::createFromFormat('Y-m-d', $frozenDate, $tz);
if (!$frozenDT) {
    $frozenDT = new DateTimeImmutable('now', $tz);
    $frozenDate = $frozenDT->format('Y-m-d');
}
$frozenLabel = $frozenDT->format('l j F Y');

// Two kinds of thing
$kind = $_POST['kind'] ?? $_GET['kind'] ?? 'event';
if (!in_array($kind, ['event', 'note'], true)) {
    $kind = 'event';
}

$errors = [];

// Form state
$form = [
    'title'       => '',
    'description' => '',
    'location'    => '',
    'start_time'  => '09:00',
    'end_time'    => '',
    'category'    => 'reminder',
    'visibility'  => 'all',
    'note_body'   => '',
];

// Labels differ for Louisa vs family
$isSubject = ($role === 'subject');
$noteHeading = $isSubject
    ? 'Add to my thoughts'
    : 'Send a message';
$noteHelp = $isSubject
    ? 'Write whatever you like. Family can read it, and it stays here.'
    : 'Louisa will see this on ' . $frozenLabel . '.';
$noteButton = $isSubject ? 'Add to my thoughts' : 'Send message';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_request();

    // ---- Note / message flow ----
    if ($kind === 'note') {
        $form['note_body'] = trim((string)($_POST['note_body'] ?? ''));

        try {
            if ($isSubject) {
                add_louisa_thought($accountId, $userId, $frozenDate, $form['note_body']);
                flash_set('success', 'Added to your thoughts.');
            } else {
                add_family_message($accountId, $userId, $frozenDate, $form['note_body']);
                flash_set('success', 'Message sent.');
            }
            header('Location: ' . base_url('calendar/index.php?date=' . $frozenDate));
            exit;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('day entry failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }

    // ---- Event flow ----
    if ($kind === 'event') {
        $form['title']       = trim((string)($_POST['title'] ?? ''));
        $form['description'] = trim((string)($_POST['description'] ?? ''));
        $form['location']    = trim((string)($_POST['location'] ?? ''));
        $form['start_time']  = trim((string)($_POST['start_time'] ?? ''));
        $form['end_time']    = trim((string)($_POST['end_time'] ?? ''));
        $form['category']    = (string)($_POST['category'] ?? 'reminder');
        $form['visibility']  = (string)($_POST['visibility'] ?? 'all');

        $utc = new DateTimeZone('UTC');

        $startAt = ($form['start_time'] !== '')
            ? (new DateTimeImmutable($frozenDate . ' ' . $form['start_time'] . ':00', $tz))
                ->setTimezone($utc)->format('Y-m-d H:i:s')
            : (new DateTimeImmutable($frozenDate . ' 00:00:00', $tz))
                ->setTimezone($utc)->format('Y-m-d H:i:s');

        $endAt = ($form['end_time'] !== '')
            ? (new DateTimeImmutable($frozenDate . ' ' . $form['end_time'] . ':00', $tz))
                ->setTimezone($utc)->format('Y-m-d H:i:s')
            : null;

        try {
            $eventId = create_event($accountId, $userId, [
                'title'       => $form['title'],
                'description' => $form['description'],
                'location'    => $form['location'],
                'start_at'    => $startAt,
                'end_at'      => $endAt,
                'all_day'     => false,
                'category'    => $form['category'],
                'visibility'  => $form['visibility'],
            ]);

            flash_set('success', 'Event added.');
            header('Location: ' . base_url('events/view.php?id=' . $eventId));
            exit;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('create_event failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Add to ' . $frozenLabel;
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="form form--wide">

    <h1 class="form__title">Add to <?= e($frozenLabel) ?></h1>

    <?php foreach ($errors as $err): ?>
        <p class="form__error" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <div class="kindchooser">

        <form method="get" class="kindchooser__form">
            <input type="hidden" name="date" value="<?= e($frozenDate) ?>">

            <button type="submit" name="kind" value="event"
                    class="kindchooser__btn <?= $kind === 'event' ? 'is-selected' : '' ?>">
                <span class="kindchooser__icon" aria-hidden="true">📅</span>
                <span class="kindchooser__title">An event</span>
                <span class="kindchooser__sub">with a time</span>
            </button>

            <button type="submit" name="kind" value="note"
                    class="kindchooser__btn <?= $kind === 'note' ? 'is-selected' : '' ?>">
                <span class="kindchooser__icon" aria-hidden="true">📝</span>
                <span class="kindchooser__title"><?= $isSubject ? 'My thoughts' : 'A message' ?></span>
                <span class="kindchooser__sub"><?= $isSubject ? 'for today' : 'for Louisa' ?></span>
            </button>
        </form>

    </div>

    <?php if ($kind === 'event'): ?>

    <form method="post" novalidate class="kindform">
        <?= csrf_field() ?>
        <input type="hidden" name="date" value="<?= e($frozenDate) ?>">
        <input type="hidden" name="kind" value="event">

        <div class="form__row">
            <label class="form__label" for="title">What's happening?</label>
            <input class="form__input" type="text" id="title" name="title"
                   value="<?= e($form['title']) ?>"
                   placeholder="e.g. Dr Patel appointment"
                   required autofocus>
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
            <textarea class="form__textarea" id="description" name="description"
                      placeholder="Anything Louisa or the family should know?"><?= e($form['description']) ?></textarea>
        </div>

        <details class="form__row" style="margin-top:16px;">
            <summary style="cursor:pointer; font-weight:600; font-size:var(--fs-body); padding:6px 0;">
                More options
            </summary>

            <div style="margin-top:12px;">
                <div class="form__row">
                    <label class="form__label" for="location">Where?</label>
                    <input class="form__input" type="text" id="location" name="location"
                           value="<?= e($form['location']) ?>"
                           placeholder="e.g. Surgery, 12 High Street">
                </div>

                <div class="form__row form__row--half">
                    <div>
                        <label class="form__label" for="category">Category</label>
                        <select class="form__select" id="category" name="category">
                            <?php
                            $cats = [
                                'reminder'    => '🔔 Reminder',
                                'appointment' => '📅 Appointment',
                                'medication'  => '💊 Medication',
                                'social'      => '👥 Social',
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

        <div class="form__actions">
            <button type="submit" class="btn">Save event</button>
            <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
               href="<?= e(base_url('calendar/index.php?date=' . $frozenDate)) ?>">Cancel</a>
        </div>
    </form>

    <?php else: ?>

    <form method="post" novalidate class="kindform">
        <?= csrf_field() ?>
        <input type="hidden" name="date" value="<?= e($frozenDate) ?>">
        <input type="hidden" name="kind" value="note">

        <h2 class="kindform__heading"><?= e($noteHeading) ?></h2>
        <p class="kindform__help"><?= e($noteHelp) ?></p>

        <div class="form__row">
            <textarea class="form__textarea" id="note_body" name="note_body"
                      placeholder="<?= $isSubject ? 'Write whatever is on your mind…' : 'Write a message for Louisa…' ?>"
                      rows="6" required autofocus><?= e($form['note_body']) ?></textarea>
        </div>

        <div class="form__actions">
            <button type="submit" class="btn"><?= e($noteButton) ?></button>
            <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
               href="<?= e(base_url('calendar/index.php?date=' . $frozenDate)) ?>">Cancel</a>
        </div>
    </form>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
