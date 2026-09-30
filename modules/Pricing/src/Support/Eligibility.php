<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

/**
 * Whether a discount's product/category conditions include a line. No conditions means every
 * line; otherwise the product must be listed or sit in a listed category (or below it).
 */
final class Eligibility
{
    /**
     * @param  array{product_ulids?: list<string>, category_ulids?: list<string>}  $conditions
     * @param  list<string>  $categoryUlids  the line's categories, including ancestors
     */
    public static function matches(array $conditions, string $productUlid, array $categoryUlids): bool
    {
        $products = $conditions['product_ulids'] ?? [];
        $categories = $conditions['category_ulids'] ?? [];

        if ($products === [] && $categories === []) {
            return true;
        }

        return in_array($productUlid, $products, true) || array_intersect($categories, $categoryUlids) !== [];
    }
}
