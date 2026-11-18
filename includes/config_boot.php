<?php
declare(strict_types=1);

$CONFIG = require __DIR__ . '/../config.php';

date_default_timezone_set($CONFIG['app']['timezone']);
// Prevent browsers from caching authenticated pages
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if ($CONFIG['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

session_name($CONFIG['app']['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $CONFIG['app']['base_path'] . '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        global $CONFIG;
        return rtrim($CONFIG['app']['base_path'], '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('e')) {
    function e(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
