<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Catalog\Data\SellableVariant;
use Modules\Catalog\Data\VariantReference;
use Modules\Catalog\Models\Category;
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

    public function sellable(array $variantUlids): array
    {
        if ($variantUlids === []) {
            return [];
        }

        $variants = ProductVariant::withTrashed()
            ->with(['product' => fn ($query) => $query->withTrashed()->with(['categories', 'media']), 'optionValues.option'])
            ->whereIn('ulid', array_values(array_unique($variantUlids)))
            ->get();

        $categoryIds = $variants->flatMap(fn (ProductVariant $variant) => $variant->product->categories->pluck('id'))->unique()->values()->all();
        $ancestors = $categoryIds === [] ? collect() : Category::query()
            ->join('category_closure', 'category_closure.ancestor_id', '=', 'categories.id')
            ->whereIn('category_closure.descendant_id', $categoryIds)
            ->get(['categories.ulid', 'category_closure.descendant_id'])
            ->groupBy('descendant_id');

        $result = [];

        foreach ($variants as $variant) {
            $product = $variant->product;
            $categoryUlids = [];

            foreach ($product->categories as $category) {
                foreach ($ancestors->get($category->id) ?? [] as $ancestor) {
                    $categoryUlids[] = (string) $ancestor->getAttribute('ulid');
                }
            }

            $categoryUlids = array_values(array_unique($categoryUlids));

            $result[$variant->ulid] = new SellableVariant(
                id: $variant->id,
                ulid: $variant->ulid,
                productUlid: $product->ulid,
                productTitle: $product->title,
                variantTitle: $variant->title,
                sku: $variant->sku,
                price: $variant->price,
                compareAtPrice: $variant->compare_at_price,
                optionValues: $variant->optionValues->mapWithKeys(fn ($value): array => [$value->option->name => $value->value])->all(),
                weightGrams: $variant->weight_grams,
                allowBackorder: $variant->allow_backorder,
                purchasable: ! $variant->trashed() && ! $product->trashed() && $product->isPublished(),
                categoryUlids: $categoryUlids,
                imageUrl: $product->getFirstMediaUrl('images', 'thumb') ?: null,
            );
        }

        return $result;
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
