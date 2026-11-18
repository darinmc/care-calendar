<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/theme.php';

if (!isset($pageTitle)) {
    $pageTitle = 'Care Calendar';
}
if (!isset($bodyClass)) {
    $bodyClass = '';
}
if (!isset($showNav)) {
    $showNav = false;
}
$showDateNav = $showDateNav ?? false;

// Which view are we in? Defaults to 'day' if not set by the page.
$currentView = $currentView ?? 'day';

// Which date-navigation labels to use?
$dateNavType = $dateNavType ?? $currentView;  // 'day' or 'week'

$flashes = flash_take_all();
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b4f6c">
    <title><?= e($pageTitle) ?> · <?= e($CONFIG['app']['name']) ?></title>
    <?php
    // ---- Theme injection: apply user's chosen colour ----
    $themePrimary = '#0b4f6c';      // default
    $themeDarker  = '#093f56';      // default (darker shade)

    $themeUser = current_user();
    if ($themeUser) {
        $userColor      = $themeUser['theme_color']      ?? null;
        $userBrightness = (int)($themeUser['theme_brightness'] ?? 0);
        $userContrast   = (int)($themeUser['theme_contrast']   ?? 30);

        if ($userColor && preg_match('/^#[0-9a-fA-F]{6}$/', $userColor)) {
            // Brightness: -100 to +100 → shifts lightness by up to ±30%
            $lightnessShift = ($userBrightness / 100) * 0.30;

            // Contrast: 0 to 60 → shifts darker row by up to 25%
            $contrastShift = ($userContrast / 100) * 0.25;

            // Apply brightness to the user's chosen colour
            $primaryAdj = adjust_hex_lightness($userColor, $lightnessShift);

            // Only darken if genuinely too pale for white text (over 70% lightness)
            $primaryAdj = ensure_max_lightness($primaryAdj, 70);

            // Derive the darker second-row colour from the primary
            $darkerAdj = adjust_hex_lightness($primaryAdj, -$contrastShift);

            // Keep the darker row visible (never below 15% lightness)
            $darkerAdj = ensure_max_lightness($darkerAdj, 70); // no-op if already dark
            $darkerLightness = get_hex_lightness($darkerAdj);
            if ($darkerLightness < 0.15) {
                $darkerAdj = adjust_hex_lightness($darkerAdj, 0.15 - $darkerLightness);
            }

            $themePrimary = $primaryAdj;
            $themeDarker  = $darkerAdj;
        }
    }
    ?>

    <link rel="icon" type="image/svg+xml" href="<?= e(base_url('assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
    <style>
        :root {
            --c-primary:   <?= e($themePrimary) ?>;
            --c-primary-h: <?= e($themeDarker) ?>;
        }
    </style>
</head>
<body class="<?= e($bodyClass) ?>">

<?php if ($showNav): ?>
<header class="topbar" role="banner">

    <div class="topbar__row topbar__row--primary">
                <a class="topbar__brand" href="<?= e(base_url('calendar/index.php')) ?>">
            <?php
                // Show the account's display name if we have one, otherwise fall back to the app name
                global $account;
                $brandName = (is_array($account) && !empty($account['display_name']))
                    ? $account['display_name']
                    : $CONFIG['app']['name'];
            ?>
            <?= e($brandName) ?>
        </a>
        <div class="topbar__actions">
            <?php if (!empty($user['first_name'])): ?>
                <span class="topbar__welcome"><?= e($user['first_name']) ?></span>
                <span class="topbar__dot" aria-hidden="true">·</span>
            <?php endif; ?>
            <a class="iconbtn" href="<?= e(base_url('account/settings.php')) ?>"
               aria-label="Settings" title="Settings">⚙</a>
            <span class="topbar__dot" aria-hidden="true">·</span>
            <form action="<?= e(base_url('auth/logout.php')) ?>" method="post" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="iconbtn iconbtn--wide" aria-label="Sign out">Sign out</button>
            </form>
        </div>
    </div>

    <?php if (!empty($showDateNav)): ?>
    <div class="topbar__row topbar__row--secondary">
        <nav class="datenav" aria-label="Date navigation">
            <a class="navbtn" href="?<?= $dateNavType === 'week' ? 'week' : ($dateNavType === 'month' ? 'month' : 'date') ?>=<?= e($prevDay) ?>" aria-label="Previous">
                <span class="navbtn__arrow" aria-hidden="true">‹</span>
                <span class="navbtn__text">Prev <?= $dateNavType === 'week' ? 'Week' : ($dateNavType === 'month' ? 'Month' : 'Day') ?></span>
            </a>

            <div class="datenav__label">
                <?= e($dateLabel) ?>
            </div>

            <a class="navbtn" href="?<?= $dateNavType === 'week' ? 'week' : ($dateNavType === 'month' ? 'month' : 'date') ?>=<?= e($nextDay) ?>" aria-label="Next">
                <span class="navbtn__text">Next <?= $dateNavType === 'week' ? 'Week' : ($dateNavType === 'month' ? 'Month' : 'Day') ?></span>
                <span class="navbtn__arrow" aria-hidden="true">›</span>
            </a>
        </nav>
    </div>
    <?php endif; ?>

    <div class="topbar__row topbar__row--views">
        <nav class="viewtabs" aria-label="Calendar view">
            <a href="<?= e(base_url('calendar/index.php')) ?>"
               class="<?= $currentView === 'day' ? 'is-active' : '' ?>">Day</a>
            <a href="<?= e(base_url('calendar/week.php')) ?>"
               class="<?= $currentView === 'week' ? 'is-active' : '' ?>">Week</a>
            <a href="<?= e(base_url('calendar/month.php')) ?>"
               class="<?= $currentView === 'month' ? 'is-active' : '' ?>">Month</a>
            <span class="is-disabled" title="Coming soon">Year</span>
        </nav>
    </div>

</header>
<?php endif; ?>

<?php if (!empty($flashes)): ?>
<div class="flashes" role="status" aria-live="polite">
    <?php foreach ($flashes as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>">
            <?= e($f['message']) ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<main class="main" role="main">
