<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Catalog\Data\VariantReference;
use Modules\Catalog\Models\ProductVariant;
use Modules\Support\ValueObjects\Money;

final class EloquentVariantCatalog implements VariantCatalog
{
    public function findByUlid(string $variantUlid): ?VariantReference
    {
        $variant = ProductVariant::query()->with('product')->where('ulid', $variantUlid)->first();

        return $variant === null ? null : self::toReference($variant);
    }

    public function findById(int $variantId): ?VariantReference
    {
        $variant = ProductVariant::query()->with('product')->whereKey($variantId)->first();

        return $variant === null ? null : self::toReference($variant);
    }

    public function findMany(array $variantIds): array
    {
        $references = [];

        foreach (ProductVariant::query()->with('product')->whereKey($variantIds)->get() as $variant) {
            $references[$variant->id] = self::toReference($variant);
        }

        return $references;
    }

    public function recordAverageCost(int $variantId, Money $cost): void
    {
        $variant = ProductVariant::query()->whereKey($variantId)->firstOrFail();
        $variant->cost = $cost;
        $variant->save();
    }

    private static function toReference(ProductVariant $variant): VariantReference
    {
        return new VariantReference(
            id: $variant->id,
            ulid: $variant->ulid,
            productUlid: $variant->product->ulid,
            sku: $variant->sku,
            title: trim($variant->product->title.' '.($variant->title ?? '')),
            allowBackorder: $variant->allow_backorder,
            currency: $variant->price->currency,
            averageCost: $variant->cost,
        );
    }
}
