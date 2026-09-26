<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Data\ProductOptionData;
use Modules\Catalog\Data\VariantData;
use Modules\Catalog\Exceptions\CatalogRuleViolation;
use Modules\Catalog\Models\ProductVariant;

/**
 * Validates a product's variants against its options and computes each variant's option
 * signature ("color=red|size=m"), which the database keeps unique per product.
 */
final class VariantMatrix
{
    /**
     * @param  array<int, ProductOptionData>  $options
     * @param  array<int, VariantData>  $variants
     * @return list<string> signatures, in variant order
     */
    public static function signatures(array $options, array $variants, ?int $ignoreProductId = null): array
    {
        if ($options === [] && count($variants) !== 1) {
            throw CatalogRuleViolation::simpleProductNeedsOneVariant();
        }

        $signatures = [];
        $skus = [];

        foreach ($variants as $variant) {
            $signature = self::signature($options, $variant);

            if (in_array($signature, $signatures, true)) {
                throw CatalogRuleViolation::duplicateCombination($variant->sku);
            }

            $sku = strtolower($variant->sku);
            $taken = in_array($sku, $skus, true) || ProductVariant::query()
                ->whereRaw('lower(sku) = ?', [$sku])
                ->when($ignoreProductId !== null, fn ($q) => $q->where('product_id', '!=', $ignoreProductId))
                ->exists();

            if ($taken) {
                throw CatalogRuleViolation::skuTaken($variant->sku);
            }

            if ($variant->compareAtPriceAmount !== null && $variant->compareAtPriceAmount <= $variant->priceAmount) {
                throw CatalogRuleViolation::compareAtBelowPrice($variant->sku);
            }

            $signatures[] = $signature;
            $skus[] = $sku;
        }

        return $signatures;
    }

    /**
     * @param  array<int, ProductOptionData>  $options
     */
    public static function signature(array $options, VariantData $variant): string
    {
        if ($options === []) {
            return '';
        }

        if (count($variant->optionValues) !== count($options)) {
            throw CatalogRuleViolation::variantNeedsEveryOption($variant->sku);
        }

        $parts = [];

        foreach ($options as $option) {
            $value = $variant->optionValues[$option->name] ?? null;

            if ($value === null) {
                throw CatalogRuleViolation::variantNeedsEveryOption($variant->sku);
            }

            if (! in_array($value, $option->values, true)) {
                throw CatalogRuleViolation::unknownOptionValue($variant->sku, $option->name, $value);
            }

            $parts[] = mb_strtolower($option->name).'='.mb_strtolower($value);
        }

        return implode('|', $parts);
    }
}
