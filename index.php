<?php
declare(strict_types=1);

require __DIR__ . '/includes/config_boot.php';

header('Location: ' . base_url('auth/login.php'));
exit;
