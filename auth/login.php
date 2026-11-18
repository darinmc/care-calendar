<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';

// Already logged in? Go to dashboard.
if (current_user()) {
    header('Location: ' . base_url('calendar/index.php'));
    exit;
}

// Handle the "just signed out" message — one-shot redirect
if (!empty($_GET['signed_out'])) {
    flash_set('success', 'You have been signed out.');
    header('Location: ' . base_url('auth/login.php'));
    exit;
}

$email = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_request();

    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $user = login_user($email, $password, $remember);
        if ($user) {
            audit(null, (int)$user['id'], 'auth.login');
            $target = $_SESSION['after_login'] ?? base_url('calendar/index.php');
            unset($_SESSION['after_login']);
            header('Location: ' . $target);
            exit;
        }
        $error = 'Incorrect email or password.';
        flash_set('error', $error);
    }
}

$pageTitle = 'Sign in';
$showNav   = false;

require __DIR__ . '/../templates/header.php';
?>

<section class="form" aria-labelledby="login-title">
    <p class="form__brand"><?= e($CONFIG['app']['name']) ?></p>
    <h1 class="form__title" id="login-title">Sign in</h1>

    <?php if ($error): ?>
        <p class="form__error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form__row">
            <label class="form__label" for="email">Email</label>
            <input class="form__input" type="email" id="email" name="email"
                   value="<?= e($email) ?>"
                   autocomplete="username" required autofocus>
        </div>

        <div class="form__row">
            <label class="form__label" for="password">Password</label>
            <input class="form__input" type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <div class="form__row form__check">
            <input type="checkbox" id="remember" name="remember" value="1" checked>
            <label for="remember">Keep me signed in on this device</label>
        </div>

        <button type="submit" class="btn btn--block">Sign in</button>
    </form>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
