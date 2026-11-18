<?php
declare(strict_types=1);

/**
 * Shared CLI bootstrap for maintenance scripts.
 * Refuses to run over the web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../includes/config_boot.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

/**
 * Minimal argument parser.
 *   --key value   → ["key" => "value"]
 *   --flag        → ["flag" => true]
 */
function parse_args(array $argv): array
{
    $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (str_starts_with($a, '--')) {
            $key = substr($a, 2);
            $next = $argv[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $args[$key] = $next;
                $i++;
            } else {
                $args[$key] = true;
            }
        }
    }
    return $args;
}

function prompt(string $label, bool $secret = false): string
{
    echo $label;
    if ($secret && DIRECTORY_SEPARATOR !== '\\') {
        // Turn off echo for password input on Linux
        shell_exec('stty -echo');
        $value = trim((string)fgets(STDIN));
        shell_exec('stty echo');
        echo PHP_EOL;
        return $value;
    }
    return trim((string)fgets(STDIN));
}

function fail(string $msg): never
{
    fwrite(STDERR, "Error: {$msg}\n");
    exit(1);
}

function ok(string $msg): void
{
    echo "✓ {$msg}\n";
}
