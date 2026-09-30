<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

/**
 * Validated data classes treat an empty list as a missing required field; leaving empty lists
 * (and nulls) out lets their defaults apply. Applied to the product and to each variant.
 */
final class Payload
{
    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public static function compact(array $product): array
    {
        if (is_array($product['variants'] ?? null)) {
            $product['variants'] = array_map(fn (mixed $variant): mixed => is_array($variant) ? self::strip($variant) : $variant, $product['variants']);
        }

        return self::strip($product);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function strip(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
