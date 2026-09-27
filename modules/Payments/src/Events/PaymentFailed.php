<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderUlid,
        public readonly string $paymentUlid,
        public readonly string $reason,
    ) {}
}
