<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/permissions.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/account.php';
require __DIR__ . '/../includes/members.php';

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];

$errors  = [];
$success = null;

/* ---------------- Handle POST ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_request();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_name') {
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last  = trim((string)($_POST['last_name'] ?? ''));

        if ($first === '' || $last === '') {
            $errors[] = 'First and last name are required.';
        } elseif (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            $errors[] = 'Names are too long (max 100 characters each).';
        } else {
            db()->prepare(
                'UPDATE users SET first_name = ?, last_name = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$first, $last, $userId]);

            audit($accountId, $userId, 'profile.name_changed', 'user', $userId);
            flash_set('success', 'Your name has been updated.');
            header('Location: ' . base_url('account/profile.php'));
            exit;
        }
    }

    if ($action === 'update_email') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif ($email === strtolower($user['email'])) {
            $errors[] = 'That is already your email address.';
        } else {
            $dup = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $dup->execute([$email]);
            if ($dup->fetch()) {
                $errors[] = 'That email is already in use by another account.';
            } else {
                db()->prepare(
                    'UPDATE users SET email = ?, is_email_verified = 0, updated_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                )->execute([$email, $userId]);

                audit($accountId, $userId, 'profile.email_changed', 'user', $userId,
                      ['old' => $user['email'], 'new' => $email]);

                flash_set('success', 'Your email has been updated. Please verify it next time you sign in.');
                header('Location: ' . base_url('account/profile.php'));
                exit;
            }
        }
    }

    if ($action === 'update_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $errors[] = 'Please fill in all three password fields.';
        } elseif (!password_verify($current, $user['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($new) < 10) {
            $errors[] = 'Your new password must be at least 10 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The two new passwords do not match.';
        } elseif ($new === $current) {
            $errors[] = 'Your new password must be different from your current one.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            db()->prepare(
                'UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$hash, $userId]);

            audit($accountId, $userId, 'profile.password_changed', 'user', $userId);
            flash_set('success', 'Your password has been changed.');
            header('Location: ' . base_url('account/profile.php'));
            exit;
        }
    }
}

$pageTitle = 'Your profile';
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="settings">

    <h1 class="settings__title">Your profile</h1>

    <?php foreach ($errors as $err): ?>
        <p class="form__error" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <div class="profile-card">
        <h2 class="profile-card__title">Your name</h2>
        <p class="profile-card__help">This is what other people in the calendar see.</p>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_name">

            <div class="form__row form__row--half">
                <div>
                    <label class="form__label" for="first_name">First name</label>
                    <input class="form__input" type="text" id="first_name" name="first_name"
                           value="<?= e($user['first_name']) ?>" required>
                </div>
                <div>
                    <label class="form__label" for="last_name">Last name</label>
                    <input class="form__input" type="text" id="last_name" name="last_name"
                           value="<?= e($user['last_name']) ?>" required>
                </div>
            </div>

            <div class="form__actions">
                <button type="submit" class="btn">Save name</button>
            </div>
        </form>
    </div>

    <div class="profile-card">
        <h2 class="profile-card__title">Your email</h2>
        <p class="profile-card__help">Used for signing in and password resets.</p>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_email">

            <div class="form__row">
                <label class="form__label" for="email">Email address</label>
                <input class="form__input" type="email" id="email" name="email"
                       value="<?= e($user['email']) ?>" required autocomplete="email">
            </div>

            <div class="form__actions">
                <button type="submit" class="btn">Save email</button>
            </div>
        </form>
    </div>

    <div class="profile-card">
        <h2 class="profile-card__title">Change password</h2>
        <p class="profile-card__help">
            Minimum 10 characters. Choose something memorable but hard for others to guess.
        </p>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_password">

            <div class="form__row">
                <label class="form__label" for="current_password">Current password</label>
                <input class="form__input" type="password" id="current_password" name="current_password"
                       autocomplete="current-password" required>
            </div>

            <div class="form__row form__row--half">
                <div>
                    <label class="form__label" for="new_password">New password</label>
                    <input class="form__input" type="password" id="new_password" name="new_password"
                           autocomplete="new-password" required>
                </div>
                <div>
                    <label class="form__label" for="confirm_password">Confirm new password</label>
                    <input class="form__input" type="password" id="confirm_password" name="confirm_password"
                           autocomplete="new-password" required>
                </div>
            </div>

            <div class="form__actions">
                <button type="submit" class="btn">Change password</button>
            </div>
        </form>
    </div>

    <div class="settings__footer">
        <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
           href="<?= e(base_url('account/settings.php')) ?>">Back to settings</a>
    </div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
