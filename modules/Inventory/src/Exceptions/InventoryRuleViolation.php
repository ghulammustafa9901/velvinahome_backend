<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use DomainException;

final class InventoryRuleViolation extends DomainException
{
    public static function reasonRequired(string $type): self
    {
        return new self("A {$type} movement requires a reason.");
    }

    public static function unknownVariant(string $ulid): self
    {
        return new self("Unknown variant [{$ulid}].");
    }

    public static function unknownLocation(string $ulid): self
    {
        return new self("Unknown or inactive location [{$ulid}].");
    }

    public static function reservationNotActive(string $ulid, string $status): self
    {
        return new self("Reservation [{$ulid}] is {$status}, not active.");
    }

    public static function sameLocation(): self
    {
        return new self('A transfer needs two different locations.');
    }

    public static function nonPositiveQuantity(): self
    {
        return new self('Quantities must be positive.');
    }
}
