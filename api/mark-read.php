<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/permissions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/account.php';
require __DIR__ . '/../includes/day_entries.php';

header('Content-Type: application/json');

$user    = current_user();
$account = current_account();

if (!$user || !$account) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];
$date      = (string)($_POST['date'] ?? $_GET['date'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid date']);
    exit;
}

if (member_role($userId, $accountId) !== 'subject') {
    echo json_encode(['ok' => true, 'cleared' => 0]);
    exit;
}

mark_family_messages_read($accountId, $userId, $date);

echo json_encode(['ok' => true]);
