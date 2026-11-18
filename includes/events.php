<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/validation.php';

/**
 * Event CRUD engine with version history.
 *
 * Nothing is ever hard-deleted. Every mutation writes a snapshot of the
 * PRIOR state to event_versions, so any change can be inspected or undone.
 */

/* ---------------- Read ---------------- */

/**
 * Fetch a single event, ensuring the user can see it.
 * Returns null if not found or not visible.
 */
function get_event(int $eventId, int $userId, int $accountId): ?array
{
    $stmt = db()->prepare(
        'SELECT e.*,
                u.first_name AS creator_first,
                u.last_name  AS creator_last
         FROM events e
         JOIN users u ON u.id = e.created_by
         WHERE e.id = ? AND e.account_id = ?
         LIMIT 1'
    );
    $stmt->execute([$eventId, $accountId]);
    $event = $stmt->fetch();

    if (!$event) {
        return null;
    }
    if (!can_see_event_visibility($userId, $accountId, $event['visibility'])) {
        return null;
    }
    return $event;
}

/**
 * Fetch events for a date range (inclusive), for the current user.
 * Deleted events excluded unless $includeDeleted is true.
 */
function get_events_for_range(
    int $accountId,
    int $userId,
    string $startDate,
    string $endDate,
    bool $includeDeleted = false
): array {
    [$visSql, $visParams] = events_visibility_filter($userId, $accountId);

    $sql = "SELECT e.*, u.first_name AS creator_first, u.last_name AS creator_last
            FROM events e
            JOIN users u ON u.id = e.created_by
                        WHERE e.account_id = ?
              AND e.kind = 'event'
              AND e.start_at >= ?
              AND e.start_at < DATE_ADD(?, INTERVAL 1 DAY)
              AND {$visSql}";

    if (!$includeDeleted) {
        $sql .= " AND e.status <> 'deleted'";
    }
    $sql .= " ORDER BY e.start_at ASC, e.id ASC";

    $params = array_merge([$accountId, $startDate, $endDate], $visParams);

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch events for a single day (the primary view).
 */
function get_events_for_day(int $accountId, int $userId, string $date): array
{
    return get_events_for_range($accountId, $userId, $date, $date);
}

/**
 * Group events by date (YYYY-MM-DD) for a range.
 * Useful for week/month views.
 */
function group_events_by_date(array $events): array
{
    $grouped = [];
    foreach ($events as $e) {
        $day = substr($e['start_at'], 0, 10);
        $grouped[$day][] = $e;
    }
    return $grouped;
}

/* ---------------- Write ---------------- */

/**
 * Create a new event.
 * Returns the new event ID, or throws on validation failure.
 */
function create_event(int $accountId, int $userId, array $data): int
{
    $clean = normalise_event_data($data);
    $errors = validate_event_data($clean);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'INSERT INTO events
                (account_id, created_by, title, description, location,
                 start_at, end_at, all_day, category, visibility, reminder_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $userId,
            $clean['title'],
            $clean['description'],
            $clean['location'],
            $clean['start_at'],
            $clean['end_at'],
            $clean['all_day'] ? 1 : 0,
            $clean['category'],
            $clean['visibility'],
            $clean['reminder_minutes'],
        ]);
        $eventId = (int)db()->lastInsertId();

        // Initial version entry: 'create' with an empty snapshot
        $stmt = db()->prepare(
            'INSERT INTO event_versions
                (event_id, version_no, changed_by, change_type, change_reason, snapshot)
             VALUES (?, 1, ?, "create", NULL, ?)'
        );
        $stmt->execute([$eventId, $userId, json_encode([], JSON_UNESCAPED_UNICODE)]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit($accountId, $userId, 'event.create', 'event', $eventId);
    return $eventId;
}

/**
 * Update an existing event. Writes a snapshot of the previous state.
 * $changeReason is stored in the version row.
 */
function update_event(
    int $eventId,
    int $accountId,
    int $userId,
    array $newData,
    ?string $changeReason = null
): void {
    $before = get_event($eventId, $userId, $accountId);
    if (!$before) {
        throw new RuntimeException('Event not found or not visible.');
    }

    // Permission check: can this user edit the event's note text?
    if (!can_edit_event_fields($userId, $accountId)) {
        throw new RuntimeException('You do not have permission to edit events.');
    }

    $clean = normalise_event_data($newData, $before);
    $errors = validate_event_data($clean);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }

    // If the note text is changing, ensure they can edit note text
    $noteChanged = ($before['description'] ?? null) !== $clean['description'];
    if ($noteChanged && !can_edit_event_note_text($userId, $accountId, (int)$before['created_by'])) {
        throw new RuntimeException(
            'Only the note\'s author, an admin, or the owner can edit its text. Please add a comment instead.'
        );
    }

    // If the change is significant, a reason is required
    $needsReason = should_require_reason($before, $clean, 'update');
    if ($needsReason && ($changeReason === null || $changeReason === '')) {
        throw new InvalidArgumentException('This change requires a reason.');
    }
    if ($changeReason !== null && $changeReason !== '') {
        $reasonError = validate_reason($changeReason);
        if ($reasonError) {
            throw new InvalidArgumentException($reasonError);
        }
    }

    db()->beginTransaction();
    try {
        // Store snapshot of PRIOR state
        $versionNo = next_version_no($eventId);
        $stmt = db()->prepare(
            'INSERT INTO event_versions
                (event_id, version_no, changed_by, change_type, change_reason, snapshot)
             VALUES (?, ?, ?, "update", ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $versionNo,
            $userId,
            $changeReason,
            json_encode($before, JSON_UNESCAPED_UNICODE),
        ]);

        // Apply the update
        $stmt = db()->prepare(
            'UPDATE events
             SET title = ?, description = ?, location = ?,
                 start_at = ?, end_at = ?, all_day = ?,
                 category = ?, visibility = ?, reminder_minutes = ?,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([
            $clean['title'],
            $clean['description'],
            $clean['location'],
            $clean['start_at'],
            $clean['end_at'],
            $clean['all_day'] ? 1 : 0,
            $clean['category'],
            $clean['visibility'],
            $clean['reminder_minutes'],
            $eventId,
            $accountId,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit($accountId, $userId, 'event.update', 'event', $eventId,
          ['reason' => $changeReason]);
}

/**
 * Soft delete: mark the event as deleted, keep the row.
 * Reason is required and validated.
 */
function delete_event(
    int $eventId,
    int $accountId,
    int $userId,
    string $reason
): void {
    $before = get_event($eventId, $userId, $accountId);
    if (!$before) {
        throw new RuntimeException('Event not found or not visible.');
    }

    // Permission: own event, or owner/admin
    $isOwn = (int)$before['created_by'] === $userId;
    if (!$isOwn && !can_delete_others_event($userId, $accountId)) {
        throw new RuntimeException('You can only delete events you created.');
    }

    $reasonError = validate_reason($reason);
    if ($reasonError) {
        throw new InvalidArgumentException($reasonError);
    }

    db()->beginTransaction();
    try {
        $versionNo = next_version_no($eventId);
        $stmt = db()->prepare(
            'INSERT INTO event_versions
                (event_id, version_no, changed_by, change_type, change_reason, snapshot)
             VALUES (?, ?, ?, "delete", ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $versionNo,
            $userId,
            $reason,
            json_encode($before, JSON_UNESCAPED_UNICODE),
        ]);

        $stmt = db()->prepare(
            'UPDATE events
             SET status = "deleted",
                 deleted_at = UTC_TIMESTAMP(),
                 deleted_by = ?,
                 delete_reason = ?
             WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([$userId, $reason, $eventId, $accountId]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit($accountId, $userId, 'event.delete', 'event', $eventId, ['reason' => $reason]);
}

/**
 * Restore a soft-deleted event from a specific version.
 * If $versionId is null, uses the most recent pre-delete snapshot.
 */
function restore_event(
    int $eventId,
    int $accountId,
    int $userId,
    ?int $versionId = null,
    ?string $reason = null
): void {
    if (!can_edit_event_fields($userId, $accountId)) {
        throw new RuntimeException('You do not have permission to restore events.');
    }

    // Load the event regardless of visibility (need to restore deleted ones)
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND account_id = ? LIMIT 1');
    $stmt->execute([$eventId, $accountId]);
    $event = $stmt->fetch();
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    // Find the snapshot to restore from
    if ($versionId !== null) {
        $stmt = db()->prepare(
            'SELECT * FROM event_versions WHERE id = ? AND event_id = ? LIMIT 1'
        );
        $stmt->execute([$versionId, $eventId]);
    } else {
        $stmt = db()->prepare(
            'SELECT * FROM event_versions
             WHERE event_id = ? AND change_type = "delete"
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute([$eventId]);
    }
    $version = $stmt->fetch();
    if (!$version) {
        throw new RuntimeException('No snapshot available to restore from.');
    }

    $snapshot = json_decode($version['snapshot'], true);
    if (!is_array($snapshot) || empty($snapshot)) {
        throw new RuntimeException('Snapshot is empty or corrupt.');
    }

    // Take a snapshot of the current (deleted) state
    db()->beginTransaction();
    try {
        $versionNo = next_version_no($eventId);
        $stmt = db()->prepare(
            'INSERT INTO event_versions
                (event_id, version_no, changed_by, change_type, change_reason, snapshot)
             VALUES (?, ?, ?, "restore", ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $versionNo,
            $userId,
            $reason ?? 'Restored',
            json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);

        // Restore fields from the snapshot
        $stmt = db()->prepare(
            'UPDATE events
             SET title = ?, description = ?, location = ?,
                 start_at = ?, end_at = ?, all_day = ?,
                 category = ?, visibility = ?, reminder_minutes = ?,
                 status = "active",
                 deleted_at = NULL, deleted_by = NULL, delete_reason = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([
            $snapshot['title'],
            $snapshot['description'],
            $snapshot['location'],
            $snapshot['start_at'],
            $snapshot['end_at'],
            (int)$snapshot['all_day'],
            $snapshot['category'],
            $snapshot['visibility'],
            $snapshot['reminder_minutes'] ?? null,
            $eventId,
            $accountId,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit($accountId, $userId, 'event.restore', 'event', $eventId,
          ['from_version' => $versionNo - 1]);
}

/**
 * Get the full version history for an event, newest first.
 */
function get_event_versions(int $eventId, int $accountId): array
{
    $stmt = db()->prepare(
        'SELECT v.*, u.first_name, u.last_name
         FROM event_versions v
         JOIN users u ON u.id = v.changed_by
         WHERE v.event_id = ?
         ORDER BY v.version_no DESC'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/* ---------------- Helpers ---------------- */

function next_version_no(int $eventId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(version_no), 0) + 1 FROM event_versions WHERE event_id = ?'
    );
    $stmt->execute([$eventId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Normalise incoming data into a consistent event array.
 * Uses $fallback for missing fields (for updates).
 */
function normalise_event_data(array $data, array $fallback = []): array
{
    return [
        'title'            => trim((string)($data['title'] ?? $fallback['title'] ?? '')),
        'description'      => trim((string)($data['description'] ?? $fallback['description'] ?? '')) ?: null,
        'location'         => trim((string)($data['location'] ?? $fallback['location'] ?? '')) ?: null,
        'start_at'         => (string)($data['start_at'] ?? $fallback['start_at'] ?? ''),
        'end_at'           => ($data['end_at'] ?? $fallback['end_at'] ?? null) ?: null,
        'all_day'          => !empty($data['all_day']) || (!empty($fallback['all_day'])),
        'category'         => (string)($data['category'] ?? $fallback['category'] ?? 'appointment'),
        'visibility'       => (string)($data['visibility'] ?? $fallback['visibility'] ?? 'all'),
        'reminder_minutes' => isset($data['reminder_minutes']) && $data['reminder_minutes'] !== ''
                                ? (int)$data['reminder_minutes']
                                : ($fallback['reminder_minutes'] ?? null),
    ];
}

function validate_event_data(array $e): array
{
    $errors = [];
    if ($e['title'] === '') {
        $errors[] = 'Title is required.';
    } elseif (mb_strlen($e['title']) > 200) {
        $errors[] = 'Title is too long (max 200 characters).';
    }

    if ($e['start_at'] === '') {
        $errors[] = 'Start date/time is required.';
    } else {
        $ts = strtotime($e['start_at']);
        if ($ts === false) {
            $errors[] = 'Start date/time is invalid.';
        }
    }

        if (!empty($e['end_at'])) {
        $tsEnd   = strtotime($e['end_at']);
        $tsStart = strtotime($e['start_at']);
        if ($tsEnd === false) {
            $errors[] = 'End date/time is invalid.';
        } elseif ($tsStart !== false && $tsEnd < $tsStart) {
            $errors[] = 'End time cannot be before the start time.';
        } elseif ($tsStart !== false && $tsEnd === $tsStart) {
            $errors[] = 'End time must be different from the start time. Leave it blank for a single-moment event.';
        }
    }

    $validCategories = ['appointment','medication','social','reminder','note','other'];
    if (!in_array($e['category'], $validCategories, true)) {
        $errors[] = 'Invalid category.';
    }

    $validVisibility = ['all','editors','owner'];
    if (!in_array($e['visibility'], $validVisibility, true)) {
        $errors[] = 'Invalid visibility.';
    }

    return $errors;
}


/**
 * Fetch soft-deleted events for a given day.
 */
function get_deleted_events_for_day(int $accountId, int $userId, string $date): array
{
    [$visSql, $visParams] = events_visibility_filter($userId, $accountId);

    $sql = "SELECT e.*, u.first_name AS creator_first, u.last_name AS creator_last,
                   d.first_name AS deleter_first, d.last_name AS deleter_last
            FROM events e
            JOIN users u ON u.id = e.created_by
            LEFT JOIN users d ON d.id = e.deleted_by
            WHERE e.account_id = ?
              AND e.status = 'deleted'
              AND e.start_at >= ?
              AND e.start_at < DATE_ADD(?, INTERVAL 1 DAY)
              AND {$visSql}
            ORDER BY e.start_at ASC, e.id ASC";

    $params = array_merge([$accountId, $date, $date], $visParams);

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Can this user restore a specific deleted event?
 *   - owner / admin: yes
 *   - anyone else: only if they created the event
 */
function can_restore_event(int $userId, int $accountId, array $event): bool
{
    $r = member_role($userId, $accountId);
    if (in_array($r, ['owner', 'admin'], true)) {
        return true;
    }
    return (int)$event['created_by'] === $userId;
}



/**
 * Given a date (Y-m-d), return the Monday and Sunday of its week.
 * Returns ['monday' => 'Y-m-d', 'sunday' => 'Y-m-d'].
 */
function get_week_bounds(string $date, DateTimeZone $tz): array
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if (!$d) {
        $d = new DateTimeImmutable('now', $tz);
    }
    // ISO: Monday = 1, Sunday = 7
    $dow = (int)$d->format('N');
    $monday = $d->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
    $sunday = $monday->modify('+6 days')->setTime(23, 59, 59);

    return [
        'monday' => $monday->format('Y-m-d'),
        'sunday' => $sunday->format('Y-m-d'),
    ];
}

/**
 * Fetch events for a full week, keyed by day (Y-m-d).
 * Includes a blank array for days with no events.
 */
function get_events_by_day_for_week(
    int $accountId,
    int $userId,
    string $mondayYmd,
    string $sundayYmd
): array {
    $events = get_events_for_range($accountId, $userId, $mondayYmd, $sundayYmd);
    $grouped = group_events_by_date($events);

    // Ensure every day of the week is present, even if empty
    $out = [];
    $start = new DateTimeImmutable($mondayYmd);
    for ($i = 0; $i < 7; $i++) {
        $day = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $out[$day] = $grouped[$day] ?? [];
    }
    return $out;
}


/**
 * Given any date, return the month's first and last days.
 * Returns ['first' => 'Y-m-d', 'last' => 'Y-m-d'].
 */
function get_month_bounds(string $date, DateTimeZone $tz): array
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if (!$d) {
        $d = new DateTimeImmutable('now', $tz);
    }
    $first = $d->modify('first day of this month')->setTime(0, 0, 0);
    $last  = $d->modify('last day of this month')->setTime(23, 59, 59);

    return [
        'first' => $first->format('Y-m-d'),
        'last'  => $last->format('Y-m-d'),
    ];
}

/**
 * Fetch events for a whole month, keyed by day (Y-m-d).
 */
function get_events_by_day_for_month(
    int $accountId,
    int $userId,
    string $firstYmd,
    string $lastYmd
): array {
    $events  = get_events_for_range($accountId, $userId, $firstYmd, $lastYmd);
    $grouped = group_events_by_date($events);

    // Ensure every day of the month is present, even empty
    $out   = [];
    $start = new DateTimeImmutable($firstYmd);
    $end   = new DateTimeImmutable($lastYmd);
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $out[$d->format('Y-m-d')] = $grouped[$d->format('Y-m-d')] ?? [];
    }
    return $out;
}
