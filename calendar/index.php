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
require __DIR__ . '/../includes/day_entries.php';

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];
$role      = member_role($userId, $accountId);
$isSubject = ($role === 'subject');

$tz = new DateTimeZone($account['timezone']);

/* ---------- Determine date ---------- */
$dateInput = $_GET['date'] ?? 'today';

if ($dateInput === 'today' || $dateInput === '') {
    $date = new DateTimeImmutable('now', $tz);
} else {
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateInput, $tz) ?: null;
    if (!$date) {
        $date = new DateTimeImmutable('now', $tz);
    }
}

$dayStart = $date->setTime(0, 0, 0);
$prevDay  = $dayStart->modify('-1 day')->format('Y-m-d');
$nextDay  = $dayStart->modify('+1 day')->format('Y-m-d');
$frozenDate = $dayStart->format('Y-m-d');

$todayYmd  = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$isPastDay = ($frozenDate < $todayYmd);

/* ---------- Fetch content ---------- */
$events        = get_events_for_day($accountId, $userId, $frozenDate);
$deletedEvents = get_deleted_events_for_day($accountId, $userId, $frozenDate);
$thoughts      = get_louisa_thoughts($accountId, $frozenDate);
$messages      = get_family_messages($accountId, $frozenDate);
$unreadCount   = count_unread_messages($accountId, $userId, $frozenDate);

$icons = [
    'appointment' => '📅',
    'medication'  => '💊',
    'social'      => '👥',
    'reminder'    => '🔔',
    'note'        => '📝',
    'other'       => '•',
];

$isToday   = ($frozenDate === $todayYmd);
$dateLabel = $dayStart->format('l j F Y');

$pageTitle   = $isToday ? 'Today' : $dateLabel;
$showNav     = true;
$showDateNav = true;
$currentView = 'day';

require __DIR__ . '/../templates/header.php';
?>

<section class="day" aria-label="Events for <?= e($dateLabel) ?>">

    <?php if (!$isToday): ?>
        <div class="return-today">
            <a class="todaybtn" href="<?= e(base_url('calendar/index.php?date=today')) ?>" title="Back to today's date">
                <span aria-hidden="true">↩</span>
                Back to today's date
            </a>
        </div>
    <?php endif; ?>

    <?php
    /* ============ Louisa's thoughts ============ */
    $thoughtsShow = (!$isPastDay) || !empty($thoughts);
    if ($thoughtsShow && ($isSubject || !empty($thoughts))) {
        $entryList = [];
                foreach ($thoughts as $t) {
            $when = (new DateTimeImmutable($t['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            $entryDate = $when->format('Y-m-d');
            $whenLabel = ($entryDate === $frozenDate)
                ? $when->format('g:i a')
                : $when->format('j M, g:i a');
            $entryList[] = [
                'who'        => null,
                'when'       => $when,
                'when_label' => $whenLabel,
                'body'       => $t['description'] ?? '',
            ];
        }

        $threadKind     = 'thoughts';
        $threadTitle    = $isSubject ? 'My thoughts' : "Louisa's thoughts";
        $threadEntries  = $entryList;
        $threadUnread   = 0;
        $threadCanAdd   = $isSubject && !$isPastDay;
        $threadAddLabel = '+ Add a thought';
        $threadModalId  = 'modal-thoughts';
        include __DIR__ . '/../includes/thread_box.php';

        $modalPastDay   = $isPastDay;
        $modalId        = 'modal-thoughts';
        $modalTitle     = $isSubject ? 'My thoughts' : "Louisa's thoughts";
        $modalSubtitle  = $dateLabel;
        $entries        = $entryList;
        $addAction      = base_url('events/create.php');
        $hiddenFields   = ['date' => $frozenDate, 'kind' => 'note'];
        $addLabel       = $isSubject ? 'Add a new thought' : 'Add a thought for Louisa';
        $addPlaceholder = $isSubject ? 'Write whatever is on your mind…' : 'Write a thought for Louisa…';
        $addButton      = $isSubject ? 'Save thought' : 'Save thought for Louisa';
        $canAdd         = $isSubject && !$isPastDay;
        $postKind       = 'note';
        $modalUnreadDate = '';
        include __DIR__ . '/../includes/thread_modal.php';

        unset($modalId, $modalTitle, $modalSubtitle, $entries, $addAction,
              $hiddenFields, $addLabel, $addPlaceholder, $addButton,
              $canAdd, $postKind, $modalUnreadDate, $modalPastDay);
    }
    ?>

    <?php
    /* ============ Family messages ============ */
    $messagesShow = (!$isPastDay) || !empty($messages);
    if ($messagesShow && (!$isSubject || !empty($messages))) {
        $entryList = [];
                foreach ($messages as $m) {
            $when = (new DateTimeImmutable($m['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            $entryDate = $when->format('Y-m-d');
            $whenLabel = ($entryDate === $frozenDate)
                ? $when->format('g:i a')
                : $when->format('j M, g:i a');
            $entryList[] = [
                'who'        => $m['first_name'],
                'when'       => $when,
                'when_label' => $whenLabel,
                'body'       => $m['description'] ?? '',
            ];
        }

        $threadKind     = 'messages';
        $threadTitle    = 'Family messages';
        $threadEntries  = $entryList;
        $threadUnread   = $unreadCount;
        $threadCanAdd   = !$isSubject && !$isPastDay;
        $threadAddLabel = '+ Send a message';
        $threadModalId  = 'modal-messages';
        include __DIR__ . '/../includes/thread_box.php';

        $modalPastDay   = $isPastDay;
        $modalId        = 'modal-messages';
        $modalTitle     = 'Family messages';
        $modalSubtitle  = $dateLabel;
        $entries        = $entryList;
        $addAction      = base_url('events/create.php');
        $hiddenFields   = ['date' => $frozenDate, 'kind' => 'note'];
        $addLabel       = 'Send a message to Louisa';
        $addPlaceholder = 'Write a message for Louisa…';
        $addButton      = 'Send message';
        $canAdd         = !$isSubject && !$isPastDay;
        $postKind       = 'note';
        $modalUnreadDate = ($isSubject && $unreadCount > 0) ? $frozenDate : '';
        include __DIR__ . '/../includes/thread_modal.php';

        unset($modalId, $modalTitle, $modalSubtitle, $entries, $addAction,
              $hiddenFields, $addLabel, $addPlaceholder, $addButton,
              $canAdd, $postKind, $modalUnreadDate, $modalPastDay);
    }
    ?>

    <?php /* ============ Events ============ */ ?>

    <?php if (empty($events)): ?>
        <div class="day__empty">
            <p>Nothing scheduled for this day.</p>
        </div>
    <?php else: ?>
        <ul class="day__list">
            <?php foreach ($events as $e): ?>
                <?php
                    $startLocal = (new DateTimeImmutable($e['start_at'], new DateTimeZone('UTC')))
                                    ->setTimezone($tz);
                    $endLocal = null;
                    if (!empty($e['end_at'])) {
                        $endLocal = (new DateTimeImmutable($e['end_at'], new DateTimeZone('UTC')))
                                        ->setTimezone($tz);
                    }
                    $icon = $icons[$e['category']] ?? '•';
                ?>
                <li>
                    <a class="day__item" href="<?= e(base_url('events/view.php?id=' . (int)$e['id'])) ?>">
                        <div class="day__time">
                            <?php if ($e['all_day']): ?>
                                <span class="day__time-all-day">All day</span>
                            <?php else: ?>
                                <?= e($startLocal->format('g:i a')) ?>
                            <?php endif; ?>
                        </div>
                        <div class="day__body">
                            <h3 class="day__title">
                                <span class="day__icon" aria-hidden="true"><?= $icon ?></span>
                                <?= e($e['title']) ?>
                            </h3>
                            <?php if ($endLocal && !$e['all_day']): ?>
                                <p class="day__meta">Until <?= e($endLocal->format('g:i a')) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($e['location'])): ?>
                                <p class="day__meta">📍 <?= e($e['location']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($e['description'])): ?>
                                <p class="day__description"><?= e($e['description']) ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($deletedEvents)): ?>
        <details class="deleted-section">
            <summary class="deleted-section__title">
                🗑️ <?= count($deletedEvents) ?> deleted
                <?= count($deletedEvents) === 1 ? 'item' : 'items' ?>
                · Show
            </summary>
            <ul class="day__list day__list--deleted" style="margin-top:14px;">
                <?php foreach ($deletedEvents as $d): ?>
                    <?php
                        $dStart = (new DateTimeImmutable($d['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);
                        $dDeleter = trim(($d['deleter_first'] ?? '') . ' ' . ($d['deleter_last'] ?? ''));
                        $canRestore = can_restore_event($userId, $accountId, $d);
                    ?>
                    <li>
                        <div class="day__item day__item--deleted">
                            <div class="day__time">
                                <?php if ($d['all_day']): ?>
                                    <span class="day__time-all-day">All day</span>
                                <?php else: ?>
                                    <?= e($dStart->format('g:i a')) ?>
                                <?php endif; ?>
                            </div>
                            <div class="day__body">
                                <h3 class="day__title">
                                    <span class="day__icon" aria-hidden="true">🗑️</span>
                                    <?= e($d['title']) ?>
                                </h3>
                                <p class="day__meta">
                                    <?php if ($dDeleter !== ''): ?>
                                        Removed by <?= e($dDeleter) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($d['delete_reason'])): ?>
                                        · “<?= e($d['delete_reason']) ?>”
                                    <?php endif; ?>
                                </p>

                                <?php if ($canRestore): ?>
                                    <form method="post" action="<?= e(base_url('events/restore.php')) ?>"
                                          class="restore-form" onsubmit="return confirm('Restore this event?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                                        <input type="hidden" name="reason" value="Restored from day view">
                                        <button type="submit" class="btn btn--ghost btn--small"
                                                style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);">
                                            Restore
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

</section>

<?php if ($isPastDay): ?>
    <div class="fab-wrap">
        <div class="fab-bubble" id="week-fab-bubble" hidden>
            You can't add to a past day
        </div>
        <button type="button" class="fab fab--hint" id="week-fab-hint">
            + Add Event or Message
        </button>
    </div>
<?php else: ?>
    <a class="fab"
       href="<?= e(base_url('events/create.php?date=' . $frozenDate)) ?>"
       aria-label="Add event or message">+ Add Event or Message</a>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
