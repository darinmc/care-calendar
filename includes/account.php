<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';

/**
 * Account context: which account is the current user viewing?
 *
 * A user can belong to multiple accounts (e.g. caring for two relatives).
 * The selected account is stored in $_SESSION['account_id'].
 */

function current_account_id(): ?int
{
    return isset($_SESSION['account_id']) ? (int)$_SESSION['account_id'] : null;
}

/**
 * Return the current account row, or null.
 * Validates that the current user has access.
 */
function current_account(): ?array
{
    static $cached = null;
    static $checked = false;
    if ($checked) {
        return $cached;
    }
    $checked = true;

    $user = current_user();
    if (!$user) {
        return null;
    }

    $accountId = current_account_id();

    // If no account chosen, or chosen one is not accessible, pick the first
    if ($accountId === null || !is_member((int)$user['id'], $accountId)) {
        $accountId = first_account_for_user((int)$user['id']);
        if ($accountId === null) {
            return null;
        }
        $_SESSION['account_id'] = $accountId;
    }

    $stmt = db()->prepare(
        'SELECT * FROM accounts WHERE id = ? AND status = "active" LIMIT 1'
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();

    $cached = $row ?: null;
    return $cached;
}

function require_account(): array
{
    $account = current_account();
    if (!$account) {
        http_response_code(403);
        exit('No account available.');
    }
    return $account;
}

function first_account_for_user(int $userId): ?int
{
    $stmt = db()->prepare(
        'SELECT account_id FROM account_members
         WHERE user_id = ? AND status = "active"
         ORDER BY (role = "owner") DESC, id ASC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['account_id'] : null;
}

function switch_account(int $accountId): bool
{
    $user = current_user();
    if (!$user || !is_member((int)$user['id'], $accountId)) {
        return false;
    }
    $_SESSION['account_id'] = $accountId;
    return true;
}

/**
 * Convenience: get the current user + current account in one call.
 * Returns [user, account] or [null, null].
 */
function current_user_and_account(): array
{
    return [current_user(), current_account()];
}
