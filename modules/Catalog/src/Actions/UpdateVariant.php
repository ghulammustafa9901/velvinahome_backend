<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Catalog\Exceptions\CatalogRuleViolation;
use Modules\Catalog\Models\ProductVariant;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\ValueObjects\Money;

/**
 * Price and fulfilment attributes of one variant. Stock is not here: it changes only through
 * Inventory Actions.
 */
final class UpdateVariant implements Action
{
    use ChecksPermission;

    public function handle(
        ProductVariant $variant,
        int $priceAmount,
        ?int $compareAtPriceAmount = null,
        ?string $barcode = null,
        ?int $weightGrams = null,
        bool $allowBackorder = false,
        ?Authorizable $actor = null,
    ): ProductVariant {
        $this->authorizePermission($actor, 'products.manage');

        if ($compareAtPriceAmount !== null && $compareAtPriceAmount <= $priceAmount) {
            throw CatalogRuleViolation::compareAtBelowPrice($variant->sku);
        }

        $currency = $variant->price->currency;

        $variant->price = Money::of($priceAmount, $currency);
        $variant->compare_at_price = $compareAtPriceAmount === null ? null : Money::of($compareAtPriceAmount, $currency);
        $variant->forceFill(['barcode' => $barcode, 'weight_grams' => $weightGrams, 'allow_backorder' => $allowBackorder])->save();

        return $variant;
    }
}
