<?php

declare(strict_types=1);

namespace Modules\Inventory\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\NotIn;
use Spatie\LaravelData\Data;

/**
 * "Edit stock" in the admin: a signed quantity change with a mandatory reason (spec §6.5).
 */
final class StockAdjustmentData extends Data
{
    public function __construct(
        public readonly string $variantUlid,
        public readonly string $locationUlid,
        #[NotIn([0])]
        public readonly int $quantity,
        #[Min(3), Max(500)]
        public readonly string $reason,
        #[In(['adjustment', 'damage'])]
        public readonly string $type = 'adjustment',
    ) {}
}
