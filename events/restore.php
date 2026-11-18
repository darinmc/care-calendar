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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

csrf_check_request();

$eventId  = (int)($_POST['id'] ?? 0);
$reason   = trim((string)($_POST['reason'] ?? 'Restored from day view'));
$returnTo = (string)($_POST['return_to'] ?? '');

$reasonError = validate_reason($reason);
if ($reasonError) {
    flash_set('error', $reasonError);
    header('Location: ' . ($returnTo !== '' ? $returnTo : base_url('calendar/index.php')));
    exit;
}

$stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND account_id = ? LIMIT 1');
$stmt->execute([$eventId, $accountId]);
$event = $stmt->fetch();

if (!$event) {
    flash_set('error', 'Event not found.');
    header('Location: ' . ($returnTo !== '' ? $returnTo : base_url('calendar/index.php')));
    exit;
}

if ($event['status'] !== 'deleted') {
    flash_set('info', 'That event is already active.');
    header('Location: ' . base_url('events/view.php?id=' . $eventId));
    exit;
}

if (!can_restore_event($userId, $accountId, $event)) {
    http_response_code(403);
    exit('You do not have permission to restore this event.');
}

try {
    restore_event($eventId, $accountId, $userId, null, $reason);
    flash_set('success', 'Event restored.');
    header('Location: ' . base_url('events/view.php?id=' . $eventId));
    exit;
} catch (Throwable $e) {
    error_log('restore failed: ' . $e->getMessage());
    flash_set('error', 'Could not restore: ' . $e->getMessage());
    header('Location: ' . ($returnTo !== '' ? $returnTo : base_url('calendar/index.php')));
    exit;
}
