<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/flash.php';

/**
 * Authentication and session handling.
 *
 * Two layers of session:
 *   1. PHP $_SESSION  — fast, in-memory cookie-based session (browser-lifetime)
 *   2. sessions table — long-lived "remember me" token (persists across browser restarts)
 *
 * The DB session also lets us show "devices" and revoke remote sessions later.
 */

const REMEMBER_COOKIE = 'cc_remember';

/**
 * Return the currently logged-in user row, or null.
 * Cached per-request.
 */
function current_user(): ?array
{
    static $cached = null;
    static $checked = false;

    if ($checked) {
        return $cached;
    }
    $checked = true;

    // 1. Normal session
    if (!empty($_SESSION['user_id'])) {
        $user = load_user_by_id((int)$_SESSION['user_id']);
        if ($user && $user['status'] === 'active') {
            $cached = $user;
            return $cached;
        }
        // Session points at a missing/disabled user — clear it
        unset($_SESSION['user_id']);
    }

    // 2. Persistent session via remember cookie
    $user = try_remember_cookie();
    if ($user) {
        $_SESSION['user_id'] = (int)$user['id'];
        $cached = $user;
        return $cached;
    }

    return null;
}

/**
 * Require login; redirect to login page if not authenticated.
 * Pass the current URL so we can return there after login.
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $return = $_SERVER['REQUEST_URI'] ?? base_url('/');
        $_SESSION['after_login'] = $return;
        header('Location: ' . base_url('auth/login.php'));
        exit;
    }
    return $user;
}

/**
 * Attempt to log in. Returns the user array on success, or null on failure.
 * On failure, increments failed_login_count and possibly locks the account.
 */
function login_user(string $email, string $password, bool $remember): ?array
{
    $email = strtolower(trim($email));

    $stmt = db()->prepare(
        'SELECT * FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always run a verify to reduce timing leaks
    if (!$user) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return null;
    }

    // Check lock
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        flash_set('error', 'Account temporarily locked. Please try again later.');
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        record_failed_login((int)$user['id']);
        return null;
    }

    if ($user['status'] !== 'active') {
        flash_set('error', 'This account has been disabled.');
        return null;
    }

    // Success — upgrade hash if needed
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $user['id']]);
    }

    // Reset counters
    db()->prepare(
        'UPDATE users SET failed_login_count = 0, locked_until = NULL,
                          last_login_at = UTC_TIMESTAMP()
         WHERE id = ?'
    )->execute([$user['id']]);

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    if ($remember) {
        create_remember_token((int)$user['id']);
    }

    // Refresh the cached user
    $user['last_login_at'] = gmdate('Y-m-d H:i:s');

    return $user;
}

/**
 * Log the current user out.
 * $allDevices = true also clears persistent tokens for every device.
 */
function logout_user(bool $allDevices = false): void
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        delete_remember_token($_COOKIE[REMEMBER_COOKIE]);
    }

    if ($allDevices && $userId) {
        db()->prepare('DELETE FROM sessions WHERE user_id = ?')
            ->execute([$userId]);
    }

        $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();

    // Clear remember cookie
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/care-calendar/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* ---------------- Internal helpers ---------------- */

function load_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function record_failed_login(int $userId): void
{
    $stmt = db()->prepare(
        'UPDATE users
         SET failed_login_count = failed_login_count + 1,
             locked_until = CASE
                 WHEN failed_login_count + 1 >= 8
                 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
                 ELSE locked_until
             END
         WHERE id = ?'
    );
    $stmt->execute([$userId]);
}

function create_remember_token(int $userId): void
{
    global $CONFIG;

    $raw  = bin2hex(random_bytes(32));
    $hash = hash_hmac('sha256', $raw, $CONFIG['security']['app_key']);
    $lifetime = (int)$CONFIG['app']['remember_lifetime'];

    $stmt = db()->prepare(
        'INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))'
    );
    $stmt->execute([
        $hash,
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $lifetime,
    ]);

    setcookie(REMEMBER_COOKIE, $raw, [
        'expires'  => time() + $lifetime,
        'path'     => '/care-calendar/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function try_remember_cookie(): ?array
{
    if (empty($_COOKIE[REMEMBER_COOKIE])) {
        return null;
    }
    global $CONFIG;
    $raw = $_COOKIE[REMEMBER_COOKIE];
    $hash = hash_hmac('sha256', $raw, $CONFIG['security']['app_key']);

    $stmt = db()->prepare(
        'SELECT s.user_id, u.*
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active') {
        // Cookie invalid — clear it
        setcookie(REMEMBER_COOKIE, '', time() - 3600, '/care-calendar/');
        return null;
    }

    // Touch last_seen
    db()->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')
       ->execute([$hash]);

    return $row;
}

function delete_remember_token(string $raw): void
{
    global $CONFIG;
    $hash = hash_hmac('sha256', $raw, $CONFIG['security']['app_key']);
    db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$hash]);
}
