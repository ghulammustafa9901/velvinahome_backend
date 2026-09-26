<?php

declare(strict_types=1);

namespace Modules\Catalog\Data;

use Modules\Support\Enums\Currency;
use Modules\Support\ValueObjects\Money;

/**
 * What other modules may know about a variant. The internal id is included because the
 * Inventory tables reference variants by foreign key inside the same database; it never
 * leaves the backend.
 */
final readonly class VariantReference
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $productUlid,
        public string $sku,
        public string $title,
        public bool $allowBackorder,
        public Currency $currency,
        public ?Money $averageCost = null,
    ) {}
}
