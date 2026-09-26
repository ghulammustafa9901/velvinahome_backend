<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Available stock at a location fell to or below its low-stock threshold (spec §6.5 alerts).
 * Raised once per crossing, not on every movement below the line.
 */
final class LowStockReached
{
    use Dispatchable;

    public function __construct(
        public readonly int $variantId,
        public readonly string $locationUlid,
        public readonly int $available,
        public readonly int $threshold,
    ) {}
}
