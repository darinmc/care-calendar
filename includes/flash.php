<?php
declare(strict_types=1);

/**
 * Flash messages: short-lived notices shown on the next page load.
 *   flash_set('error', 'Incorrect email or password.');
 *   $msgs = flash_take_all();
 */

function flash_set(string $type, string $message): void
{
    if (empty($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take_all(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}
