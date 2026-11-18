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

$tz        = new DateTimeZone($account['timezone']);
$todayYmd  = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

// Determine which month to show
$monthInput = $_GET['month'] ?? 'this';
if ($monthInput === 'this' || $monthInput === '') {
    $anchorDate = $todayYmd;
} else {
    $try = DateTimeImmutable::createFromFormat('Y-m-d', $monthInput, $tz);
    $anchorDate = $try ? $try->format('Y-m-d') : $todayYmd;
}

$bounds = get_month_bounds($anchorDate, $tz);
$first  = $bounds['first'];
$last   = $bounds['last'];

$eventsByDay  = get_events_by_day_for_month($accountId, $userId, $first, $last);
$threadCounts = get_thread_counts_for_range($accountId, $first, $last);

// Prev / next month anchors
$firstDT    = new DateTimeImmutable($first, $tz);
$prevMonth  = $firstDT->modify('-1 month')->format('Y-m-d');
$nextMonth  = $firstDT->modify('+1 month')->format('Y-m-d');

// Is today in this month?
$thisMonth  = (new DateTimeImmutable($todayYmd, $tz))->format('Y-m');
$isThisMonth = ($firstDT->format('Y-m') === $thisMonth);

// Display label
$monthLabel = $firstDT->format('F Y');

// Build the calendar grid: 6 weeks max, starting Monday
$dow    = (int)$firstDT->format('N'); // 1=Mon, 7=Sun
$gridStart = $firstDT->modify('-' . ($dow - 1) . ' days');

$weeks = [];
$cursor = $gridStart;
for ($w = 0; $w < 6; $w++) {
    $row = [];
    for ($i = 0; $i < 7; $i++) {
        $row[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    $weeks[] = $row;
    // Stop early if we've covered the whole month AND finished the current week
    if ($cursor > new DateTimeImmutable($last . ' 23:59:59', $tz) && $cursor->format('N') === '1') {
        break;
    }
}

$pageTitle   = $monthLabel;
$showNav     = true;
$showDateNav = true;
$dateNavType = 'month';
$prevDay     = $prevMonth;
$nextDay     = $nextMonth;
$isToday     = $isThisMonth;
$dateLabel   = $monthLabel;
$currentView = 'month';

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

<section class="month" aria-label="<?= e($monthLabel) ?>">

    <?php if (!$isThisMonth): ?>
        <div class="return-today">
            <a class="todaybtn" href="<?= e(base_url('calendar/month.php')) ?>" title="Back to this month">
                <span aria-hidden="true">↩</span>
                Back to this month
            </a>
        </div>
    <?php endif; ?>

    <div class="month__weekdays" aria-hidden="true">
        <span>Mon</span>
        <span>Tue</span>
        <span>Wed</span>
        <span>Thu</span>
        <span>Fri</span>
        <span>Sat</span>
        <span>Sun</span>
    </div>

    <div class="month__grid">
        <?php foreach ($weeks as $week): ?>
            <?php foreach ($week as $dayDT): ?>
                <?php
                    $ymd           = $dayDT->format('Y-m-d');
                    $inMonth       = ($ymd >= $first && $ymd <= $last);
                    $isTodayCell   = ($ymd === $todayYmd);
                    $dayEvents     = $eventsByDay[$ymd] ?? [];
                    $counts        = $threadCounts[$ymd] ?? ['thoughts' => 0, 'messages' => 0];
                    $hasThreads    = ($counts['thoughts'] > 0 || $counts['messages'] > 0);
                    $shownEvents   = array_slice($dayEvents, 0, 2);
                    $remaining     = max(0, count($dayEvents) - 2);
                ?>
                <a class="month__day<?= $isTodayCell ? ' is-today' : '' ?><?= $inMonth ? '' : ' is-outside' ?>"
                   href="<?= e(base_url('calendar/index.php?date=' . $ymd)) ?>"
                   aria-label="<?= e($dayDT->format('l j F Y')) ?>">
                    <span class="month__day-num">
                        <span><?= e($dayDT->format('j')) ?></span>
                        <span class="month__today-label">( Today )</span>
                    </span>

                    <?php if ($hasThreads): ?>
                        <span class="month__threads">
                            <?php if ($counts['thoughts'] > 0): ?>
                                <span class="month__thread month__thread--thoughts">
                                    📝 <?= (int)$counts['thoughts'] ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($counts['messages'] > 0): ?>
                                <span class="month__thread month__thread--messages">
                                    💬 <?= (int)$counts['messages'] ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>

                    <?php foreach ($shownEvents as $e): ?>
                        <?php
                            $startLocal = (new DateTimeImmutable($e['start_at'], new DateTimeZone('UTC')))
                                            ->setTimezone($tz);
                            $icon = $icons[$e['category']] ?? '•';
                        ?>
                        <span class="month__event">
                            <span class="month__event-icon" aria-hidden="true"><?= $icon ?></span>
                            <span class="month__event-time"><?= e($startLocal->format('g:i a')) ?></span>
                            <span class="month__event-title"><?= e($e['title']) ?></span>
                        </span>
                    <?php endforeach; ?>

                    <?php if ($remaining > 0): ?>
                        <span class="month__more">+<?= (int)$remaining ?> more</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

</section>

<div class="fab-wrap">
    <div class="fab-bubble" id="week-fab-bubble" hidden>
        Choose a day on the calendar first
    </div>
    <button type="button" class="fab fab--hint" id="week-fab-hint">
        + Add Event or Message
    </button>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
