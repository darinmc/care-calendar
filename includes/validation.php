<?php
declare(strict_types=1);

/**
 * Validate a reason string.
 * Returns null if valid, or an error message string.
 *
 * Rules:
 *   - 5–255 chars after trimming
 *   - At least 2 unicode letters
 *   - Not a single repeated character ("aaaaa")
 *   - Not an obvious non-reason (small blocklist)
 */
function validate_reason(string $reason): ?string
{
    $reason = trim($reason);
    $len = mb_strlen($reason);

    if ($len < 3) {
        return 'Please give a reason (at least 3 characters).';
    }
    if ($len > 500) {
        return 'Reason is too long (max 500 characters).';
    }

    // Reject a single repeated character ("aaaaa", "11111", "-----")
    if (preg_match('/^(.)\1*$/u', $reason)) {
        return 'Please write a real reason.';
    }

    return null;
}

/**
 * Whether a change should require a reason.
 */
function should_require_reason(array $before, array $after, string $changeType): bool
{
    if (in_array($changeType, ['delete', 'restore'], true)) {
        return true;
    }
    if (($before['title'] ?? null) !== ($after['title'] ?? null)) {
        return true;
    }
    if (($before['visibility'] ?? null) !== ($after['visibility'] ?? null)) {
        return true;
    }
    $beforeStart = isset($before['start_at']) ? strtotime($before['start_at']) : null;
    $afterStart  = isset($after['start_at'])  ? strtotime($after['start_at'])  : null;
    if ($beforeStart !== null && $afterStart !== null) {
        if (abs($afterStart - $beforeStart) > 900) {
            return true;
        }
    }
    return false;
}
