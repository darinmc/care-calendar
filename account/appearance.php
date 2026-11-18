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

$user    = require_login();
$account = require_account();

$userId    = (int)$user['id'];
$accountId = (int)$account['id'];

$presets = [
    'Blue'  => '#0b4f6c',
    'Navy'  => '#1e3a5f',
    'Teal'  => '#0d6e6e',
    'Green' => '#2d5d2c',
    'Olive' => '#6b6b1e',
    'Brown' => '#6f4e37',
    'Plum'  => '#4a2c6f',
    'Rose'  => '#8a2846',
    'Slate' => '#3d4b5c',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_request();

    // Handle reset
    if (($_POST['action'] ?? '') === 'reset') {
        db()->prepare(
            'UPDATE users SET theme_color = NULL, theme_brightness = 0, theme_contrast = 30 WHERE id = ?'
        )->execute([$userId]);

        audit($accountId, $userId, 'appearance.reset', 'user', $userId);
        flash_set('success', 'Appearance reset to default.');
        header('Location: ' . base_url('account/appearance.php'));
        exit;
    }

    $color      = trim((string)($_POST['theme_color'] ?? ''));
    $brightness = (int)($_POST['theme_brightness'] ?? 0);
    $contrast   = (int)($_POST['theme_contrast'] ?? 30);

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $errors[] = 'Please choose a valid colour.';
    }

    $brightness = max(-100, min(100, $brightness));
    $contrast   = max(0, min(60, $contrast));

    if (empty($errors)) {
        db()->prepare(
            'UPDATE users SET theme_color = ?, theme_brightness = ?, theme_contrast = ? WHERE id = ?'
        )->execute([$color, $brightness, $contrast, $userId]);

        audit($accountId, $userId, 'appearance.updated', 'user', $userId);
        flash_set('success', 'Appearance updated.');
        header('Location: ' . base_url('account/appearance.php'));
        exit;
    }
}

$currentColor      = $user['theme_color']      ?? '#0b4f6c';
$currentBrightness = (int)($user['theme_brightness'] ?? 0);
$currentContrast   = (int)($user['theme_contrast']   ?? 30);

$pageTitle = 'Appearance';
$showNav   = true;

require __DIR__ . '/../templates/header.php';
?>

<section class="settings account-appearance">

    <h1 class="appearance-title">🎨 Appearance</h1>

    <?php foreach ($errors as $err): ?>
        <p class="form__error" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" novalidate class="appearance-form">

        <?= csrf_field() ?>
        <input type="hidden" name="theme_color" id="theme_color_hidden" value="<?= e($currentColor) ?>">

        <div class="appearance-card">

            <h2 class="appearance-section-title">Colour theme</h2>

            <div class="appearance-presets">
                <?php foreach ($presets as $name => $hex): ?>
                    <button type="button" class="appearance-swatch<?= ($currentColor === $hex) ? ' is-selected' : '' ?>"
                            data-color="<?= e($hex) ?>"
                            style="--swatch: <?= e($hex) ?>;"
                            title="<?= e($name) ?>">
                        <span class="appearance-swatch__circle"></span>
                        <span class="appearance-swatch__name"><?= e($name) ?></span>
                    </button>
                <?php endforeach; ?>
</div>

            <div class="appearance-columns">

                <div class="appearance-col appearance-col--sliders">

                    <h3 class="appearance-col__title">Brightness</h3>
                    <div class="appearance-slider__row">
                        <span class="appearance-slider__end">Darker</span>
                        <input type="range" id="theme_brightness" name="theme_brightness"
                               class="appearance-range" min="-100" max="100" step="5"
                               value="<?= (int)$currentBrightness ?>">
                        <span class="appearance-slider__end">Lighter</span>
                    </div>

                    <h3 class="appearance-col__title" style="margin-top:12px;">Contrast</h3>
                    <div class="appearance-slider__row">
                        <span class="appearance-slider__end">Subtle</span>
                        <input type="range" id="theme_contrast" name="theme_contrast"
                               class="appearance-range" min="0" max="60" step="5"
                               value="<?= (int)$currentContrast ?>">
                        <span class="appearance-slider__end">Strong</span>
                    </div>

                </div>

                <div class="appearance-col appearance-col--custom">

                    <h3 class="appearance-col__title">Pick your own colour</h3>

                    <label class="appearance-custom-big" for="theme_color_custom">
                        <span class="appearance-custom-big__swatch" id="custom-preview-swatch" style="--custom-current: <?= e($currentColor) ?>;"></span>
                        <span class="appearance-custom-big__label">Pick your own</span>
                        <input type="color" id="theme_color_custom"
                               class="appearance-custom-big__input"
                               value="<?= e($currentColor) ?>">
                    </label>

                    <p class="appearance-col__hint">Any colour you like.</p>

                </div>

            </div>

            <div class="form__actions" style="margin-top:16px;">
                <button type="submit" class="btn">Save appearance</button>
                <button type="submit" name="action" value="reset" class="btn btn--ghost"
                        style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
                        onclick="return confirm('Reset to default appearance?');">
                    Reset to default
                </button>
                <a class="btn btn--ghost" style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
                   href="<?= e(base_url('account/settings.php')) ?>">Cancel</a>
            </div>

        </div>

    </form>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
