<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Write an audit_log entry. Safe to call anywhere; never throws.
 */
function audit(
    ?int $accountId,
    ?int $userId,
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    ?array $meta = null
): void {
    try {
        if (!function_exists('db')) {
            return;
        }
        $stmt = db()->prepare(
            'INSERT INTO audit_log
             (account_id, user_id, action, target_type, target_id, meta, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $userId,
            $action,
            $targetType,
            $targetId,
            $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('audit() failed: ' . $e->getMessage());
    }
}
