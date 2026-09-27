<?php

declare(strict_types=1);

namespace Modules\Catalog\Data;

use Modules\Support\ValueObjects\Money;

/**
 * Everything Cart, Pricing and Checkout need to sell one variant, read fresh from the
 * catalog every time a cart is priced — never trusted from the client or a cache.
 */
final readonly class SellableVariant
{
    /**
     * @param  array<string, string>  $optionValues
     * @param  list<string>  $categoryUlids  including every ancestor category
     */
    public function __construct(
        public int $id,
        public string $ulid,
        public string $productUlid,
        public string $productTitle,
        public ?string $variantTitle,
        public string $sku,
        public Money $price,
        public ?Money $compareAtPrice,
        public array $optionValues,
        public ?int $weightGrams,
        public bool $allowBackorder,
        public bool $purchasable,
        public array $categoryUlids,
        public ?string $imageUrl,
    ) {}
}
