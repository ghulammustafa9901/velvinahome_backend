<?php

declare(strict_types=1);

namespace Modules\Support\Exceptions;

use InvalidArgumentException;
use Modules\Support\Enums\Currency;

/**
 * Thrown when two amounts in different currencies are combined or compared.
 *
 * This is deliberately an exception rather than an implicit conversion: there is no correct
 * exchange rate to guess at, and silently adding PKR to USD would corrupt an order total.
 */
final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(sprintf(
            'Cannot combine amounts in different currencies: %s and %s.',
            $left->value,
            $right->value,
        ));
    }
}
