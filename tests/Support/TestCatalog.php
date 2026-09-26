<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Actions\CreateProduct;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Models\Location;

/**
 * Catalog and stock fixtures. Every call runs inside the current tenant.
 */
final class TestCatalog
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function simpleProduct(array $overrides = [], bool $publish = false): Product
    {
        $sku = $overrides['sku'] ?? 'SKU-'.strtoupper(Str::random(8));
        unset($overrides['sku']);

        $product = app(CreateProduct::class)->handle(ProductData::from(array_merge([
            'title' => 'Test product '.Str::random(6),
            'variants' => [[
                'sku' => $sku,
                'priceAmount' => $overrides['priceAmount'] ?? 249900,
                'allowBackorder' => $overrides['allowBackorder'] ?? false,
            ]],
        ], array_diff_key($overrides, ['priceAmount' => 1, 'allowBackorder' => 1]))));

        if ($publish) {
            app(ChangeProductStatus::class)->handle($product, ProductStatus::Active);
        }

        return $product->refresh();
    }

    public static function defaultLocation(): Location
    {
        return Location::query()->where('is_default', true)->firstOrFail();
    }

    public static function secondLocation(): Location
    {
        return Location::query()->firstOrCreate(
            ['code' => 'SHOP1'],
            ['name' => 'Shop one', 'type' => 'shop', 'is_default' => false, 'is_active' => true, 'priority' => 10],
        );
    }
}
