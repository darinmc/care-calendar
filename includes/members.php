<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';

/**
 * Members and invitations helpers.
 */

/**
 * Get all active members of an account with their user details.
 * Ordered: owner first, then admins, then by name.
 */
function get_account_members(int $accountId): array
{
    $stmt = db()->prepare(
        'SELECT am.id AS membership_id, am.role, am.is_subject, am.accepted_at,
                u.id AS user_id, u.email, u.first_name, u.last_name, u.status AS user_status
         FROM account_members am
         JOIN users u ON u.id = am.user_id
         WHERE am.account_id = ?
           AND am.status = "active"
         ORDER BY
           CASE am.role
             WHEN "owner" THEN 1
             WHEN "admin" THEN 2
             WHEN "subject" THEN 3
             WHEN "family" THEN 4
             WHEN "guest" THEN 5
             ELSE 6
           END,
           u.first_name ASC, u.last_name ASC'
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll();
}

/**
 * Count active members + pending invitations.
 * Used for the "N people" summary and for enforcing member limits.
 */
function count_account_members(int $accountId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM account_members
         WHERE account_id = ? AND status IN ("active","invited")'
    );
    $stmt->execute([$accountId]);
    $members = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM invitations
         WHERE account_id = ? AND accepted_at IS NULL AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute([$accountId]);
    $invited = (int)$stmt->fetchColumn();

    return ['active' => $members, 'invited' => $invited, 'total' => $members + $invited];
}

/**
 * Human-friendly label for a role.
 */
function role_label(string $role): string
{
    return match ($role) {
        'owner'   => 'Owner',
        'admin'   => 'Admin',
        'family'  => 'Family',
        'guest'   => 'Guest',
        'subject' => 'Calendar owner',
        default   => ucfirst($role),
    };
}

/**
 * Description of what a role can do, for the members page and invite form.
 */
function role_description(string $role): string
{
    return match ($role) {
        'owner'   => 'Full control, including billing. Only one owner.',
        'admin'   => 'Can manage members and all events. Cannot manage billing.',
        'family'  => 'Can add and edit events, comment, and delete their own.',
        'guest'   => 'Can see the calendar and add events, but limited admin.',
        'subject' => 'The person this calendar is for. Can add and edit events.',
        default   => '',
    };
}

/**
 * Get a pending invitation by token. Returns null if not found/expired.
 */
function get_pending_invitation(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*, a.display_name AS account_name, a.subject_name,
                u.first_name AS inviter_first, u.last_name AS inviter_last
         FROM invitations i
         JOIN accounts a ON a.id = i.account_id
         JOIN users u ON u.id = i.invited_by
         WHERE i.token = ?
           AND i.accepted_at IS NULL
           AND i.expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Create a pending invitation. Returns the raw token.
 */
function create_invitation(int $accountId, string $email, string $role, int $invitedBy): string
{
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email address.');
    }

    $validRoles = ['admin','family','guest','subject'];
    if (!in_array($role, $validRoles, true)) {
        throw new InvalidArgumentException('Invalid role.');
    }

    // Check member limit
    $account = db()->prepare('SELECT member_limit FROM accounts WHERE id = ? LIMIT 1');
    $account->execute([$accountId]);
    $memberLimit = (int)$account->fetchColumn();

    $counts = count_account_members($accountId);

    if ($counts['active'] + $counts['invited'] >= $memberLimit) {
        throw new RuntimeException(
            "This account has reached its limit of {$memberLimit} members. " .
            "Upgrade the plan to invite more, or remove someone first."
        );
    }

    // Check no duplicate pending invite for same email
    $dup = db()->prepare(
        'SELECT id FROM invitations
         WHERE account_id = ? AND email = ? AND accepted_at IS NULL AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $dup->execute([$accountId, $email]);
    if ($dup->fetch()) {
        throw new RuntimeException('There is already a pending invitation for that email.');
    }

    // Check not already an active member
    $already = db()->prepare(
        'SELECT am.id FROM account_members am
         JOIN users u ON u.id = am.user_id
         WHERE am.account_id = ? AND u.email = ? AND am.status = "active"
         LIMIT 1'
    );
    $already->execute([$accountId, $email]);
    if ($already->fetch()) {
        throw new RuntimeException('That person is already a member of this account.');
    }

    $token = bin2hex(random_bytes(32));

    $stmt = db()->prepare(
        'INSERT INTO invitations
            (account_id, email, role, token, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY))'
    );
    $stmt->execute([$accountId, $email, $role, $token, $invitedBy]);

    return $token;
}

/**
 * Get all pending invitations for an account.
 */
function get_pending_invitations(int $accountId): array
{
    $stmt = db()->prepare(
        'SELECT i.*, u.first_name AS inviter_first, u.last_name AS inviter_last
         FROM invitations i
         JOIN users u ON u.id = i.invited_by
         WHERE i.account_id = ?
           AND i.accepted_at IS NULL
           AND i.expires_at > UTC_TIMESTAMP()
         ORDER BY i.created_at DESC'
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll();
}

/**
 * Revoke (delete) a pending invitation.
 */
function revoke_invitation(int $invitationId, int $accountId): void
{
    $stmt = db()->prepare(
        'DELETE FROM invitations WHERE id = ? AND account_id = ? AND accepted_at IS NULL'
    );
    $stmt->execute([$invitationId, $accountId]);
}
