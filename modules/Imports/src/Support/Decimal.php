<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

/**
 * Decimal strings from other systems ("1299.50", "0.25") to integers, without ever passing
 * through a binary floating-point number (ADR-0008). Rounds half up at the last kept digit.
 */
final class Decimal
{
    public static function scaled(mixed $value, int $digits): ?int
    {
        if (is_int($value)) {
            return $value * 10 ** $digits;
        }

        if (! is_string($value) || preg_match('/^\s*(-)?(\d{1,15})(?:\.(\d+))?\s*$/', $value, $match) !== 1) {
            return null;
        }

        $fraction = $match[3] ?? '';
        $kept = (int) substr(str_pad($fraction, $digits, '0'), 0, $digits);
        $scaled = (int) $match[2] * 10 ** $digits + $kept;

        if (strlen($fraction) > $digits && (int) substr($fraction, $digits, 1) >= 5) {
            $scaled++;
        }

        return $match[1] === '-' ? -$scaled : $scaled;
    }
}
