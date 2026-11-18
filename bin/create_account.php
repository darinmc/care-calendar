<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Create a care account and attach users to it.
 *
 * Usage:
 *   php bin/create_account.php \
 *     --name "Louisa's Calendar" \
 *     --subject "Louisa Smith" \
 *     --owner sarah@example.com \
 *     --plan 2
 *
 * Optional:
 *   --timezone Europe/London
 *   --add louisa@example.com:subject
 *   --add john@example.com:family
 *   --add jane@example.com:guest
 */

$args = parse_args($argv);

$name        = trim((string)($args['name'] ?? ''));
$subjectName = trim((string)($args['subject'] ?? ''));
$ownerEmail  = strtolower(trim((string)($args['owner'] ?? '')));
$planId      = (int)($args['plan'] ?? 2);
$timezone    = (string)($args['timezone'] ?? 'Europe/London');

if ($name === '')        fail('--name is required');
if ($subjectName === '') fail('--subject is required');
if ($ownerEmail === '')  fail('--owner is required (email of the owner user)');

// Verify owner exists
$stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$ownerEmail]);
$owner = $stmt->fetch();
if (!$owner) fail("No user found with email {$ownerEmail}. Create them first with create_user.php");

// Verify plan exists
$stmt = db()->prepare('SELECT * FROM plans WHERE id = ? AND is_active = 1');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) fail("No active plan with ID {$planId}");

// Verify timezone is valid
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    fail("Invalid timezone: {$timezone}");
}

db()->beginTransaction();
try {
    // Create account
    $stmt = db()->prepare(
        'INSERT INTO accounts
            (display_name, subject_name, timezone, plan_id, member_limit, status, created_by)
         VALUES (?, ?, ?, ?, ?, "active", ?)'
    );
    $stmt->execute([
        $name,
        $subjectName,
        $timezone,
        $planId,
        (int)$plan['member_limit'],
        (int)$owner['id'],
    ]);
    $accountId = (int)db()->lastInsertId();

    // Add owner
    add_member($accountId, (int)$owner['id'], 'owner', 0, (int)$owner['id']);

    // Add optional members
    // --add email:role  (can be repeated, but our parser keeps last; support comma-list)
    $addList = $args['add'] ?? null;
    if ($addList !== null) {
        // Support either a single value or comma-separated list
        $entries = is_array($addList) ? $addList : explode(',', (string)$addList);
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$email, $role] = array_pad(explode(':', $entry, 2), 2, 'family');
            $email = strtolower(trim($email));
            $role  = trim($role);

            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if (!$u) fail("--add: no user found with email {$email}");

            $isSubject = ($role === 'subject') ? 1 : 0;
            add_member($accountId, (int)$u['id'], $role, $isSubject, (int)$owner['id']);
            ok("Added {$email} as {$role}");
        }
    }

    // Billing row
    $stmt = db()->prepare(
        'INSERT INTO subscriptions (account_id, plan_id, status, current_period_end)
         VALUES (?, ?, "trialing", DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY))'
    );
    $stmt->execute([$accountId, $planId]);

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    fail('Account creation failed: ' . $e->getMessage());
}

ok("Account created: ID {$accountId}, '{$name}'");
ok("Owner: {$ownerEmail}");
ok("Plan: {$plan['name']} ({$plan['member_limit']} members max)");
ok("Timezone: {$timezone}");

// ---------- helper ----------

function add_member(int $accountId, int $userId, string $role, int $isSubject, int $invitedBy): void
{
    static $validRoles = ['owner', 'admin', 'family', 'guest', 'subject'];
    if (!in_array($role, $validRoles, true)) {
        fail("Invalid role: {$role}");
    }

    $stmt = db()->prepare(
        'INSERT INTO account_members
            (account_id, user_id, role, is_subject, invited_by, invited_at, accepted_at, status)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), "active")'
    );
    $stmt->execute([$accountId, $userId, $role, $isSubject, $invitedBy]);
}
