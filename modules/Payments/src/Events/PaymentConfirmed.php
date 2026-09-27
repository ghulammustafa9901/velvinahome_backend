<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money for an order is confirmed — by staff verifying a transfer, by COD collection, or (later)
 * by a verified gateway webhook. Never by a browser redirect (spec §6.6).
 */
final class PaymentConfirmed
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderUlid,
        public readonly string $paymentUlid,
        public readonly string $methodCode,
        public readonly int $amount,
    ) {}
}
