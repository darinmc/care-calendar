<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Roles:
 *   owner   — set up & pays. Full control. Can transfer ownership.
 *   admin   — trusted family/carer. Manages members but not billing.
 *   family  — regular family/friend. Adds, edits, comments, deletes own.
 *   guest   — distant/observer. Can add and comment, cannot modify others.
 *   subject — the person the calendar is about. Participant, not admin.
 *             A subject will NEVER be owner or admin.
 *
 * Core principle: fields get corrected (with version history),
 * words get commented on — except owner/admin may correct note text.
 */

function account_membership(int $userId, int $accountId): ?array
{
    static $cache = [];
    $key = $userId . ':' . $accountId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare(
        'SELECT * FROM account_members
         WHERE user_id = ? AND account_id = ? AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([$userId, $accountId]);
    $row = $stmt->fetch();

    $cache[$key] = $row ?: null;
    return $cache[$key];
}

function is_member(int $userId, int $accountId): bool
{
    return account_membership($userId, $accountId) !== null;
}

function member_role(int $userId, int $accountId): ?string
{
    $m = account_membership($userId, $accountId);
    return $m['role'] ?? null;
}

/* ---------------- Events ---------------- */

function can_view_events(int $userId, int $accountId): bool
{
    return is_member($userId, $accountId);
}

function can_create_events(int $userId, int $accountId): bool
{
    // Everyone active can add — including guests and subject.
    return is_member($userId, $accountId);
}

function can_edit_own_event(int $userId, int $accountId, int $ownerUserId): bool
{
    if (!is_member($userId, $accountId)) {
        return false;
    }
    return $userId === $ownerUserId;
}

function can_edit_event_fields(int $userId, int $accountId): bool
{
    // Everyone active can correct fields — version history protects originals.
    return is_member($userId, $accountId);
}

function can_edit_event_note_text(int $userId, int $accountId, int $noteOwnerUserId): bool
{
    if (!is_member($userId, $accountId)) {
        return false;
    }
    // You can always edit your own note.
    if ($userId === $noteOwnerUserId) {
        return true;
    }
    // Owner and admin can correct anyone's note text.
    // NOTE: 'subject' is deliberately NOT in this list.
    $r = member_role($userId, $accountId);
    return in_array($r, ['owner', 'admin'], true);
}

function can_comment_on_event(int $userId, int $accountId): bool
{
    return is_member($userId, $accountId);
}

function can_delete_own_event(int $userId, int $accountId, int $eventOwnerUserId): bool
{
    if (!is_member($userId, $accountId)) {
        return false;
    }
    return $userId === $eventOwnerUserId;
}

function can_delete_others_event(int $userId, int $accountId): bool
{
    $r = member_role($userId, $accountId);
    return in_array($r, ['owner', 'admin'], true);
}

function can_view_history(int $userId, int $accountId): bool
{
    // Everyone active can see the history of an event they can see.
    return is_member($userId, $accountId);
}

/* ---------------- Members & account ---------------- */

function can_invite_members(int $userId, int $accountId): bool
{
    $r = member_role($userId, $accountId);
    return in_array($r, ['owner', 'admin'], true);
}

function can_change_role(int $userId, int $accountId, string $targetRole): bool
{
    $r = member_role($userId, $accountId);
    $validRoles = ['owner', 'admin', 'family', 'guest', 'subject'];

    if (!in_array($targetRole, $validRoles, true)) {
        return false;
    }
    if ($r === 'owner') {
        return true;
    }
    if ($r === 'admin') {
        return in_array($targetRole, ['family', 'guest', 'subject'], true);
    }
    return false;
}

function can_remove_members(int $userId, int $accountId): bool
{
    return can_invite_members($userId, $accountId);
}

function can_manage_billing(int $userId, int $accountId): bool
{
    return member_role($userId, $accountId) === 'owner';
}

function can_manage_account(int $userId, int $accountId): bool
{
    $r = member_role($userId, $accountId);
    return in_array($r, ['owner', 'admin'], true);
}

function can_view_audit(int $userId, int $accountId): bool
{
    $r = member_role($userId, $accountId);
    return in_array($r, ['owner', 'admin'], true);
}

/* ---------------- Visibility ---------------- */

function can_see_event_visibility(int $userId, int $accountId, string $visibility): bool
{
    $r = member_role($userId, $accountId);
    if ($r === null) {
        return false;
    }
    return match ($visibility) {
        'all'     => true,
        'editors' => in_array($r, ['owner', 'admin', 'family'], true),
        'owner'   => in_array($r, ['owner', 'admin'], true),
        default   => false,
    };
}

function events_visibility_filter(int $userId, int $accountId): array
{
    $r = member_role($userId, $accountId);
    return match ($r) {
        'owner', 'admin'    => ['1=1', []],
        'family', 'subject' => ["visibility IN ('all','editors')", []],
        default             => ["visibility = 'all'", []],
    };
}
