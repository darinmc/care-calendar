<?php
declare(strict_types=1);

/**
 * Colour helpers for the appearance/theming feature.
 * All functions take and return '#rrggbb' hex strings.
 */

/**
 * Adjust lightness by a delta (-1.0 to +1.0).
 * -0.3 = 30% darker, +0.3 = 30% lighter.
 * Result clamped to 5%–95% lightness.
 */
function adjust_hex_lightness(string $hex, float $delta): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return '#' . $hex;
    }

    [$h, $s, $l] = hex_to_hsl($hex);
    $l = max(0.05, min(0.95, $l + $delta));

    [$r, $g, $b] = hsl_to_rgb($h, $s, $l);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Get the perceptual lightness (0-1) of a hex colour.
 * Useful for contrast checks.
 */
function get_hex_lightness(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return 0.5;
    }
    [, , $l] = hex_to_hsl($hex);
    return $l;
}

/**
 * If the colour is lighter than $maxL% lightness, darken it to that threshold.
 * Only acts when the colour is genuinely too pale for white text.
 */
function ensure_max_lightness(string $hex, float $maxL): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return '#' . $hex;
    }

    [$h, $s, $l] = hex_to_hsl($hex);
    $maxL = $maxL / 100;

    if ($l > $maxL) {
        [$r, $g, $b] = hsl_to_rgb($h, $s, $maxL);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    return '#' . $hex;
}

/**
 * Convert hex to [hue (0-360), saturation (0-1), lightness (0-1)].
 */
function hex_to_hsl(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return [0.0, 0.0, 0.5];
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l   = ($max + $min) / 2;

    if ($max === $min) {
        return [0.0, 0.0, $l];
    }

    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

    if ($max === $r) {
        $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
    } elseif ($max === $g) {
        $h = (($b - $r) / $d + 2) / 6;
    } else {
        $h = (($r - $g) / $d + 4) / 6;
    }

    return [$h * 360, $s, $l];
}

/**
 * Convert HSL to [r, g, b] as 0-255 integers.
 */
function hsl_to_rgb(float $h, float $s, float $l): array
{
    $h = fmod($h, 360) / 360;

    if ($s == 0) {
        $v = (int)round($l * 255);
        return [$v, $v, $v];
    }

    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;

    $r = hue_to_rgb($p, $q, $h + 1/3);
    $g = hue_to_rgb($p, $q, $h);
    $b = hue_to_rgb($p, $q, $h - 1/3);

    return [
        (int)round($r * 255),
        (int)round($g * 255),
        (int)round($b * 255),
    ];
}

function hue_to_rgb(float $p, float $q, float $t): float
{
    if ($t < 0) $t += 1;
    if ($t > 1) $t -= 1;
    if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
    if ($t < 1/2) return $q;
    if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
    return $p;
}
