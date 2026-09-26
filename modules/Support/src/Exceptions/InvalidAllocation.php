<?php

declare(strict_types=1);

namespace Modules\Support\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an amount cannot be split as asked. Allocation must always account for every
 * minor unit, so an ambiguous request fails rather than quietly losing money.
 */
final class InvalidAllocation extends InvalidArgumentException
{
    public static function noWeights(): self
    {
        return new self('Allocation requires at least one weight.');
    }

    public static function negativeWeight(int $weight): self
    {
        return new self("Allocation weights must be non-negative, got {$weight}.");
    }

    public static function zeroTotalWeight(): self
    {
        return new self('Allocation weights must not all be zero.');
    }

    public static function invalidPartCount(int $parts): self
    {
        return new self("Cannot split into {$parts} parts; at least 1 is required.");
    }

    public static function cannotSumEmptyWithoutCurrency(): self
    {
        return new self('Summing an empty list requires an explicit currency.');
    }
}
