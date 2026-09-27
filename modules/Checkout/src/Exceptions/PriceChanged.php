<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use DomainException;
use Modules\Support\ValueObjects\Money;

/**
 * Maps to `409 price_changed`: the total the customer saw is not the total now (a price,
 * rate, coupon or tax changed in between). The client shows the new total and asks again.
 */
final class PriceChanged extends DomainException
{
    private Money $expected;

    private Money $actual;

    public static function between(Money $expected, Money $actual): self
    {
        $exception = new self('The order total has changed. Please review your order.');
        $exception->expected = $expected;
        $exception->actual = $actual;

        return $exception;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return ['expected_total' => $this->expected, 'total' => $this->actual];
    }
}
