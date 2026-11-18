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

if (!can_view_history($userId, $accountId)) {
    http_response_code(403);
    exit('You do not have permission to view history.');
}

$tz = new DateTimeZone($account['timezone']);

// Load the event even if deleted — history is what matters here
$stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND account_id = ? LIMIT 1');
$stmt->execute([$eventId, $accountId]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

// Past-day restriction: only owner/admin can restore past events
$todayYmd     = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$eventDateYmd = substr($event['start_at'], 0, 10);
$eventIsPast  = ($eventDateYmd < $todayYmd);
$role           = member_role($userId, $accountId);
$isOwnerOrAdmin = in_array($role, ['owner', 'admin'], true);
$canRestoreAny  = (!$eventIsPast || $isOwnerOrAdmin);

// Handle restore request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    csrf_check_request();

    if (!$canRestoreAny) {
        flash_set('error', 'This event is in the past. Only an owner or admin can restore it.');
        header('Location: ' . base_url('events/view.php?id=' . $eventId));
        exit;
    }

    $versionId = (int)($_POST['version_id'] ?? 0);
    $reason    = trim((string)($_POST['reason'] ?? 'Restored from history'));

    $reasonError = validate_reason($reason);
    if ($reasonError) {
        flash_set('error', $reasonError);
    } else {
        try {
            restore_event($eventId, $accountId, $userId, $versionId, $reason);
            flash_set('success', 'Event restored from that version.');
            header('Location: ' . base_url('events/view.php?id=' . $eventId));
            exit;
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
    }
}

$versions = get_event_versions($eventId, $accountId);

$pageTitle = 'History: ' . $event['title'];
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="event">
    <h1 class="event__title">History</h1>
    <p class="event__meta" style="margin-bottom:28px;">
        <span class="event__label">Event:</span>
        <a href="<?= e(base_url('events/view.php?id=' . $eventId)) ?>" style="color:var(--c-primary);">
            <?= e($event['title']) ?>
        </a>
    </p>

    <?php if ($eventIsPast && !$isOwnerOrAdmin): ?>
        <div class="flash flash--warning" style="margin-bottom:20px;">
            This event is in the past. You can see the history below, but only an owner or admin can restore a previous version.
        </div>
    <?php endif; ?>

    <?php if (empty($versions)): ?>
        <div class="event__section">
            <p>No history available yet.</p>
        </div>
    <?php else: ?>
        <div class="event__section">
            <h2>All changes (newest first)</h2>
            <ul class="history">
                <?php foreach ($versions as $i => $v): ?>
                    <?php
                        $changeType = $v['change_type'];
                        $who = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                        $when = (new DateTimeImmutable($v['created_at'], new DateTimeZone('UTC')))
                                    ->setTimezone($tz);
                        $label = match ($changeType) {
                            'create'  => 'Created',
                            'update'  => 'Edited',
                            'delete'  => 'Deleted',
                            'restore' => 'Restored',
                            default   => $changeType,
                        };
                    ?>
                    <li>
                        <div class="history__when">
                            <?= e($when->format('D j M Y, g:i a')) ?>
                        </div>
                        <div class="history__who">
                            <strong><?= e($label) ?></strong>
                            <?php if ($who !== ''): ?>
                                · by <?= e($who) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($v['change_reason'])): ?>
                            <div class="history__reason">
                                <strong>Reason:</strong> <?= e($v['change_reason']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($changeType === 'update' || $changeType === 'delete'): ?>
                            <?php
                                $snapshot = json_decode($v['snapshot'] ?? 'null', true);
                                if (is_array($snapshot) && !empty($snapshot)):
                                    $snapStart = null;
                                    if (!empty($snapshot['start_at'])) {
                                        $snapStart = (new DateTimeImmutable($snapshot['start_at'], new DateTimeZone('UTC')))
                                                        ->setTimezone($tz);
                                    }
                            ?>
                                <details style="margin-top:8px;">
                                    <summary style="cursor:pointer; color:var(--c-primary); font-weight:600;">
                                        See what it was before this change
                                    </summary>
                                    <div style="margin-top:10px; padding:12px; background:var(--c-bg); border:1px solid var(--c-border); border-radius:var(--radius-sm);">
                                        <p style="margin:4px 0;"><strong>Title:</strong> <?= e($snapshot['title'] ?? '') ?></p>
                                        <?php if ($snapStart): ?>
                                            <p style="margin:4px 0;"><strong>Start:</strong> <?= e($snapStart->format('D j M Y, g:i a')) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($snapshot['location'])): ?>
                                            <p style="margin:4px 0;"><strong>Location:</strong> <?= e($snapshot['location']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($snapshot['description'])): ?>
                                            <p style="margin:4px 0;"><strong>Notes:</strong> <?= e($snapshot['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </details>

                                <?php if ($changeType !== 'create' && $canRestoreAny): ?>
                                    <form method="post" style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;"
                                          onsubmit="return confirm('Restore this event to the version shown above?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?= (int)$eventId ?>">
                                        <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                                        <input class="form__input" type="text" name="reason"
                                               placeholder="Why restore this?" required
                                               style="max-width:280px; min-height:auto; padding:8px 12px; font-size:var(--fs-body);">
                                        <button type="submit" class="btn" style="padding:8px 16px; font-size:var(--fs-body); min-height:auto;">
                                            Restore this version
                                        </button>
                                    </form>
                                <?php elseif ($changeType !== 'create' && !$canRestoreAny): ?>
                                    <p style="margin-top:10px; font-style:italic; color:var(--c-muted);">
                                        Only an owner or admin can restore past events.
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="event__actions">
        <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
           href="<?= e(base_url('events/view.php?id=' . $eventId)) ?>">Back to event</a>
    </div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
