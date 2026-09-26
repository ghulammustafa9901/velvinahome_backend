<?php

declare(strict_types=1);

namespace Modules\Support\Enums;

/**
 * Currencies the platform can operate in.
 *
 * Currency is a tenant setting (spec §1.3), not a constant: PKR is only the default. The
 * list is an enum rather than free text so an unknown code cannot reach a money column,
 * and so minor-unit handling is defined per currency rather than assumed to be two digits.
 */
enum Currency: string
{
    case PKR = 'PKR';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case AED = 'AED';
    case SAR = 'SAR';

    /**
     * Digits after the decimal point. Money is stored in minor units, so this is what
     * converts between the stored integer and a human-readable amount.
     */
    public function minorUnitDigits(): int
    {
        return match ($this) {
            self::PKR, self::USD, self::EUR, self::GBP, self::AED, self::SAR => 2,
        };
    }

    /**
     * The number of minor units in one major unit (100 for a two-digit currency).
     */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->minorUnitDigits();
    }

    public function symbol(): string
    {
        return match ($this) {
            self::PKR => 'Rs',
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::AED => 'AED',
            self::SAR => 'SAR',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PKR => 'Pakistani Rupee',
            self::USD => 'US Dollar',
            self::EUR => 'Euro',
            self::GBP => 'Pound Sterling',
            self::AED => 'UAE Dirham',
            self::SAR => 'Saudi Riyal',
        };
    }
}
