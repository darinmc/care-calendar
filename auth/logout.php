<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';

// Only POST — protects against accidental logout via <img> or link prefetch
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

csrf_check_request();

if ($user = current_user()) {
    audit(null, (int)$user['id'], 'auth.logout');
}

logout_user();

// Use a query parameter so the flash survives the session destroy
header('Location: ' . base_url('auth/login.php?signed_out=1'));
exit;
