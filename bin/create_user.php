<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Create a user account.
 *
 * Usage:
 *   php bin/create_user.php --email s@example.com --first Sarah --last Smith
 *   (prompts for password)
 *
 *   php bin/create_user.php --email s@example.com --first Sarah --last Smith --password "Secret123!"
 *   (non-interactive — for scripts)
 */

$args = parse_args($argv);

$email = strtolower(trim((string)($args['email'] ?? '')));
$first = trim((string)($args['first'] ?? ''));
$last  = trim((string)($args['last']  ?? ''));

if ($email === '') fail('--email is required');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address');
if ($first === '') fail('--first is required');
if ($last === '')  fail('--last is required');

// Check email is not taken
$stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    fail("A user with email {$email} already exists.");
}

// Get password
if (!empty($args['password'])) {
    $password = (string)$args['password'];
} else {
    echo "Creating user: {$first} {$last} <{$email}>\n";
    $password = prompt('Password (will not echo): ', true);
    $confirm  = prompt('Confirm password: ', true);
    if ($password !== $confirm) fail('Passwords do not match.');
}

if (strlen($password) < 10) {
    fail('Password must be at least 10 characters.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = db()->prepare(
    'INSERT INTO users (email, password_hash, first_name, last_name, is_email_verified, status)
     VALUES (?, ?, ?, ?, 1, "active")'
);
$stmt->execute([$email, $hash, $first, $last]);
$userId = (int)db()->lastInsertId();

ok("User created: ID {$userId}, {$first} {$last} <{$email}>");
