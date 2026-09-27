<?php

declare(strict_types=1);

namespace Modules\Tax\Data;

use Modules\Support\ValueObjects\Money;

final readonly class TaxLineData
{
    public function __construct(
        public string $name,
        public int $rateBps,
        public Money $amount,
        // Inclusive: the tax is already inside the price. Exclusive: it is added on top.
        public bool $inclusive,
    ) {}
}
