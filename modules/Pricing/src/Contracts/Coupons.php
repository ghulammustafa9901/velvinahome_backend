<?php

declare(strict_types=1);

namespace Modules\Pricing\Contracts;

use Modules\Support\ValueObjects\Money;

interface Coupons
{
    /**
     * Record the use of a coupon by an order, inside the placing transaction and under a row
     * lock, so a usage limit can never be exceeded by concurrent orders.
     *
     * @throws \Modules\Pricing\Exceptions\CouponUnavailable
     */
    public function redeem(string $code, string $orderUlid, ?int $customerId, ?string $email, Money $discount): void;

    /**
     * Give the use back (order cancelled before fulfilment).
     */
    public function void(string $orderUlid): void;
}
