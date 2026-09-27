<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Modules\Orders\Models\Order;

/**
 * Every order-changing Action starts by taking the order's row lock, so two staff members (or
 * staff and a payment callback) acting at once are serialised rather than interleaved.
 */
final class OrderLock
{
    public static function take(string $orderUlid): Order
    {
        return Order::query()->where('ulid', $orderUlid)->lockForUpdate()->firstOrFail();
    }
}
