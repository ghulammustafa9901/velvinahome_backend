<?php

declare(strict_types=1);

namespace Modules\Shipping\Data;

/**
 * A courier's answer about one shipment. `unknown` means "nothing to act on" (not found yet,
 * courier unavailable); only `delivered` and `returned` change an order.
 */
final readonly class CourierTracking
{
    public const STATUSES = ['in_transit', 'delivered', 'returned', 'unknown'];

    public function __construct(
        public string $status,
        public ?string $detail = null,
    ) {}
}
