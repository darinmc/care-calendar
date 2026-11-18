<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';

/**
 * Day entries — append-only notes and messages for a specific day.
 *
 * Three kinds of content sit on the same day:
 *   - kind = 'event'    → timed events (handled in events.php)
 *   - kind = 'note'     → Louisa's own thoughts (append-only)
 *   - kind = 'message'  → family messages (append-only, shared thread)
 *
 * Nothing in notes or messages can be edited or deleted. Ever.
 */

/**
 * Fetch Louisa's thoughts for a given day (YYYY-MM-DD).
 */
function get_louisa_thoughts(int $accountId, string $date): array
{
    $stmt = db()->prepare(
        'SELECT e.*, u.first_name, u.last_name
         FROM events e
         JOIN users u ON u.id = e.created_by
         WHERE e.account_id = ?
           AND e.kind = "note"
           AND e.status <> "deleted"
           AND e.start_at >= ?
           AND e.start_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY e.created_at ASC'
    );
    $stmt->execute([$accountId, $date, $date]);
    return $stmt->fetchAll();
}

/**
 * Fetch family messages for a given day (YYYY-MM-DD).
 */
function get_family_messages(int $accountId, string $date): array
{
    $stmt = db()->prepare(
        'SELECT e.*, u.first_name, u.last_name
         FROM events e
         JOIN users u ON u.id = e.created_by
         WHERE e.account_id = ?
           AND e.kind = "message"
           AND e.status <> "deleted"
           AND e.start_at >= ?
           AND e.start_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY e.created_at ASC'
    );
    $stmt->execute([$accountId, $date, $date]);
    return $stmt->fetchAll();
}

/**
 * Count unread family messages for a given day, for the given user.
 * A message is unread for Louisa (subject) if read_at is NULL.
 * For everyone else this returns 0.
 */
function count_unread_messages(int $accountId, int $userId, string $date): int
{
    // Only the subject has unread tracking
    if (member_role($userId, $accountId) !== 'subject') {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM events
         WHERE account_id = ?
           AND kind = "message"
           AND status <> "deleted"
           AND read_at IS NULL
           AND start_at >= ?
           AND start_at < DATE_ADD(?, INTERVAL 1 DAY)'
    );
    $stmt->execute([$accountId, $date, $date]);
    return (int)$stmt->fetchColumn();
}

/**
 * Add Louisa's thought for a day. Append-only.
 * $date is a YYYY-MM-DD string in the account timezone.
 */
function add_louisa_thought(
    int $accountId,
    int $userId,
    string $date,
    string $body
): int {
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Please write something first.');
    }
    if (mb_strlen($body) > 2000) {
        throw new InvalidArgumentException('That is too long (max 2000 characters).');
    }

    // Store at noon UTC on the given date, so timezone shifts can't move the day
    $startAt = $date . ' 12:00:00';

    $stmt = db()->prepare(
        'INSERT INTO events
            (account_id, kind, created_by, title, description, start_at, end_at,
             all_day, category, visibility, status)
         VALUES (?, "note", ?, ?, ?, ?, NULL, 0, "note", "all", "active")'
    );
    $stmt->execute([
        $accountId,
        $userId,
        mb_substr($body, 0, 200), // title snippet
        $body,
        $startAt,
    ]);
    $id = (int)db()->lastInsertId();

    audit($accountId, $userId, 'day.note_added', 'event', $id);
    return $id;
}

/**
 * Add a family message for a day. Append-only.
 */
function add_family_message(
    int $accountId,
    int $userId,
    string $date,
    string $body
): int {
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Please write something first.');
    }
    if (mb_strlen($body) > 2000) {
        throw new InvalidArgumentException('That is too long (max 2000 characters).');
    }

    $startAt = $date . ' 12:00:00';

    $stmt = db()->prepare(
        'INSERT INTO events
            (account_id, kind, created_by, title, description, start_at, end_at,
             all_day, category, visibility, status)
         VALUES (?, "message", ?, ?, ?, ?, NULL, 0, "note", "all", "active")'
    );
    $stmt->execute([
        $accountId,
        $userId,
        mb_substr($body, 0, 200),
        $body,
        $startAt,
    ]);
    $id = (int)db()->lastInsertId();

    audit($accountId, $userId, 'day.message_added', 'event', $id);
    return $id;
}

/**
 * Mark all family messages for a given day as read by Louisa.
 */
function mark_family_messages_read(int $accountId, int $userId, string $date): void
{
    if (member_role($userId, $accountId) !== 'subject') {
        return;
    }

    $stmt = db()->prepare(
        'UPDATE events
         SET read_at = UTC_TIMESTAMP()
         WHERE account_id = ?
           AND kind = "message"
           AND status <> "deleted"
           AND read_at IS NULL
           AND start_at >= ?
           AND start_at < DATE_ADD(?, INTERVAL 1 DAY)'
    );
    $stmt->execute([$accountId, $date, $date]);
}

/**
 * Get thread counts for each day in a range.
 * Returns ['YYYY-MM-DD' => ['thoughts' => int, 'messages' => int], ...]
 */
function get_thread_counts_for_range(
    int $accountId,
    string $startDate,
    string $endDate
): array {
    $sql = "
        SELECT DATE(start_at) AS day, kind, COUNT(*) AS n
        FROM events
        WHERE account_id = ?
          AND kind IN ('note', 'message')
          AND status <> 'deleted'
          AND start_at >= ?
          AND start_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY DATE(start_at), kind
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute([$accountId, $startDate, $endDate]);
    $rows = $stmt->fetchAll();

    // Seed every day in the range with zeros
    $out = [];
    $start = new DateTimeImmutable($startDate);
    $end   = new DateTimeImmutable($endDate);
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $out[$d->format('Y-m-d')] = ['thoughts' => 0, 'messages' => 0];
    }

    // Fill counts
    foreach ($rows as $r) {
        $day = $r['day'];
        if (!isset($out[$day])) {
            $out[$day] = ['thoughts' => 0, 'messages' => 0];
        }
        if ($r['kind'] === 'note') {
            $out[$day]['thoughts'] = (int)$r['n'];
        } elseif ($r['kind'] === 'message') {
            $out[$day]['messages'] = (int)$r['n'];
        }
    }

    return $out;
}
