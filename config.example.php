<?php
/**
 * Care Calendar — example config.
 * Copy to config.php and fill in real values.
 * config.php is gitignored and never committed.
 */

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'care_calendar',
        'user'     => 'care_calendar_app',
        'pass'     => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    'app' => [
        'name'           => 'Care Calendar',
        'base_path'      => '/care-calendar',   // no trailing slash
        'timezone'       => 'Europe/London',
        'session_name'   => 'cc_session',
        'session_lifetime' => 60 * 60 * 24 * 30, // 30 days
        'remember_lifetime' => 60 * 60 * 24 * 90, // 90 days
        'debug'          => false,               // true on dev only
    ],

    'security' => [
        // 32-byte hex string — used for hashing session tokens, CSRF secrets.
        // Generate: php -r "echo bin2hex(random_bytes(32));"
        'app_key' => 'CHANGE_ME_64_HEX_CHARS',
    ],
];
