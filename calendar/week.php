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

$tz = new DateTimeZone($account['timezone']);
$todayYmd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

// Determine which week to show
$weekInput = $_GET['week'] ?? 'this';
if ($weekInput === 'this' || $weekInput === '') {
    $anchorDate = $todayYmd;
} else {
    // Any date within the week will do
    $try = DateTimeImmutable::createFromFormat('Y-m-d', $weekInput, $tz);
    $anchorDate = $try ? $try->format('Y-m-d') : $todayYmd;
}

$bounds = get_week_bounds($anchorDate, $tz);
$monday = $bounds['monday'];
$sunday = $bounds['sunday'];

$eventsByDay = get_events_by_day_for_week($accountId, $userId, $monday, $sunday);
$threadCounts = get_thread_counts_for_range($accountId, $monday, $sunday);

// Prev / next week anchors
$prevWeek = (new DateTimeImmutable($monday, $tz))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTimeImmutable($monday, $tz))->modify('+7 days')->format('Y-m-d');

// Is today in this week?
$thisMonday = get_week_bounds($todayYmd, $tz)['monday'];
$isThisWeek = ($thisMonday === $monday);

// Date range label
$mondayDT = new DateTimeImmutable($monday, $tz);
$sundayDT = new DateTimeImmutable($sunday, $tz);
if ($mondayDT->format('F') === $sundayDT->format('F')) {
    $weekLabel = $mondayDT->format('j') . '–' . $sundayDT->format('j F Y');
} else {
    $weekLabel = $mondayDT->format('j M') . ' – ' . $sundayDT->format('j M Y');
}

$pageTitle   = 'Week of ' . $weekLabel;
$showNav     = true;
$showDateNav = true;
$dateNavType = 'week';
$prevDay     = $prevWeek;
$nextDay     = $nextWeek;
$isToday     = $isThisWeek;
$dateLabel   = $weekLabel;
$currentView = 'week';

$icons = [
    'appointment' => '📅',
    'medication'  => '💊',
    'social'      => '👥',
    'reminder'    => '🔔',
    'note'        => '📝',
    'other'       => '•',
];

require __DIR__ . '/../templates/header.php';
?>

<section class="week" aria-label="Week of <?= e($weekLabel) ?>">

<?php if (!$isThisWeek): ?>
    <div class="return-today">
        <a class="todaybtn" href="<?= e(base_url('calendar/week.php')) ?>" title="Back to this week">
            <span aria-hidden="true">↩</span>
            Back to this week
        </a>
    </div>
<?php endif; ?>

<div class="week__grid">
    <?php
    $mondayDT = new DateTimeImmutable($monday, $tz);
    for ($i = 0; $i < 7; $i++):
        $dayDT = $mondayDT->modify('+' . $i . ' days');
        $ymd = $dayDT->format('Y-m-d');
        $isTodayCell = ($ymd === $todayYmd);
        $dayEvents = $eventsByDay[$ymd] ?? [];
    ?>
        <div class="week__day <?= $isTodayCell ? 'is-today' : '' ?>">
            <a class="week__day-header" href="<?= e(base_url('calendar/index.php?date=' . $ymd)) ?>">
                <span class="week__day-name"><?= e($dayDT->format('D')) ?></span>
                <span class="week__day-num"><?= e($dayDT->format('j')) ?></span>
                <span class="week__day-month"><?= e($dayDT->format('M')) ?></span>
            </a>

                        <div class="week__day-body">
                <?php
                    $counts = $threadCounts[$ymd] ?? ['thoughts' => 0, 'messages' => 0];
                ?>

                <?php if ($counts['thoughts'] > 0): ?>
                    <a class="week__thread week__thread--thoughts"
                       href="<?= e(base_url('calendar/index.php?date=' . $ymd)) ?>"
                       aria-label="<?= (int)$counts['thoughts'] ?> thoughts on <?= e($dayDT->format('j M')) ?>">
                        <span class="week__thread__icon" aria-hidden="true">📝</span>
                        <span class="week__thread__count"><?= (int)$counts['thoughts'] ?></span>
                        <span class="week__thread__word"><?= $counts['thoughts'] === 1 ? 'Thought' : 'Thoughts' ?></span>
                    </a>
                <?php endif; ?>

                <?php if ($counts['messages'] > 0): ?>
                    <a class="week__thread week__thread--messages"
                       href="<?= e(base_url('calendar/index.php?date=' . $ymd)) ?>"
                       aria-label="<?= (int)$counts['messages'] ?> messages on <?= e($dayDT->format('j M')) ?>">
                        <span class="week__thread__icon" aria-hidden="true">💬</span>
                        <span class="week__thread__count"><?= (int)$counts['messages'] ?></span>
                        <span class="week__thread__word"><?= $counts['messages'] === 1 ? 'Message' : 'Messages' ?></span>
                    </a>
                <?php endif; ?>

                <?php if (($counts['thoughts'] > 0 || $counts['messages'] > 0) && !empty($dayEvents)): ?>
                    <span class="week__day-divider" aria-hidden="true"></span>
                <?php endif; ?>

                <?php if (empty($dayEvents)): ?>
                    <p class="week__empty">—</p>
                <?php else: ?>
                    <?php foreach ($dayEvents as $e): ?>
                        <?php
                            $startLocal = (new DateTimeImmutable($e['start_at'], new DateTimeZone('UTC')))
                                            ->setTimezone($tz);
                            $icon = $icons[$e['category']] ?? '•';
                        ?>
                        <a class="week__event" href="<?= e(base_url('events/view.php?id=' . (int)$e['id'])) ?>">
                            <span class="week__event-meta">
                                <span class="week__event-icon" aria-hidden="true"><?= $icon ?></span>
                                <span class="week__event-time">
                                    <?php if ($e['all_day']): ?>All day<?php else: ?><?= e($startLocal->format('g:i a')) ?><?php endif; ?>
                                </span>
                            </span>
                            <span class="week__event-title"><?= e($e['title']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endfor; ?>
</div>

<div class="fab-wrap">
    <div class="fab-bubble" id="week-fab-bubble" hidden>
        Choose a day on the calendar first
    </div>
    <button type="button" class="fab fab--hint" id="week-fab-hint">
        + Add Event or Message
    </button>
</div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
