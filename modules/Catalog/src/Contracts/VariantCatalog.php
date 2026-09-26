<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

use Modules\Catalog\Data\VariantReference;
use Modules\Support\ValueObjects\Money;

/**
 * The Catalog's published surface for Inventory (and, later, Cart and Orders).
 */
interface VariantCatalog
{
    public function findByUlid(string $variantUlid): ?VariantReference;

    public function findById(int $variantId): ?VariantReference;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, VariantReference> keyed by variant id
     */
    public function findMany(array $variantIds): array;

    /**
     * Weighted-average unit cost, maintained by Inventory on goods receipt (spec §6.5.5).
     */
    public function recordAverageCost(int $variantId, Money $cost): void;
}
