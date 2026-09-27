<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Catalog\Models\ProductVariant;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Models\StockLevel;
use Modules\Payments\Models\PaymentMethod;

/**
 * Commerce fixtures. Methods that touch the database run inside the current tenant.
 */
final class TestCommerce
{
    /**
     * A published product with one variant and `$stock` units at the default location.
     */
    public static function stockedVariant(int $priceAmount = 100_000, int $stock = 10, bool $backorder = false): ProductVariant
    {
        $product = TestCatalog::simpleProduct(['priceAmount' => $priceAmount, 'allowBackorder' => $backorder], publish: true);
        $variant = $product->variants()->firstOrFail();

        if ($stock > 0) {
            app(ReceiveStock::class)->handle(StockDocumentData::from([
                'locationUlid' => TestCatalog::defaultLocation()->ulid,
                'lines' => [['variantUlid' => $variant->ulid, 'quantity' => $stock]],
            ]));
        }

        return $variant;
    }

    /**
     * @return array{on_hand: int, reserved: int}
     */
    public static function stock(ProductVariant $variant): array
    {
        $level = StockLevel::query()->where('variant_id', $variant->id)->where('location_id', TestCatalog::defaultLocation()->id)->firstOrFail();

        return ['on_hand' => $level->on_hand, 'reserved' => $level->reserved];
    }

    public static function enableTransfers(): void
    {
        PaymentMethod::query()->where('code', 'manual_transfer')->firstOrFail()->forceFill([
            'is_enabled' => true,
            'config' => ['instructions' => "Meezan Bank\nAccount title: Test Brand\nIBAN: PK00TEST0000000000000000"],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function address(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ayesha',
            'last_name' => 'Khan',
            'phone' => '+92 300 1234567',
            'line1' => 'House 12, Street 4',
            'city' => 'Lahore',
            'province' => 'Punjab',
            'country_code' => 'PK',
        ], $overrides);
    }

    public static function idempotencyKey(): string
    {
        return 'test-'.Str::lower(Str::random(24));
    }
}
