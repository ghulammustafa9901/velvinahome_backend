<?php

declare(strict_types=1);

namespace Modules\Cart\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartItem;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Inventory\Contracts\StockReader;
use Modules\Inventory\Exceptions\InsufficientStock;
use Modules\Support\Contracts\Action;

/**
 * Adds to or sets the quantity of a line. The variant must be for sale right now and in
 * stock for the requested quantity (unless it takes backorders) — re-validated against the
 * database on every change, never trusted from the storefront (spec §6.4).
 *
 * `$mode = 'add'` adds to an existing line; `'set'` replaces it; a resulting quantity of 0
 * removes the line.
 */
final class SetCartItem implements Action
{
    public const MAX_QUANTITY = 999;

    public function __construct(
        private readonly VariantCatalog $catalog,
        private readonly StockReader $stock,
    ) {}

    public function handle(Cart $cart, string $variantUlid, int $quantity, string $mode = 'add'): Cart
    {
        $existing = $cart->items()->where('variant_ulid', $variantUlid)->first();
        $target = $mode === 'add' ? ($existing->quantity ?? 0) + $quantity : $quantity;

        if ($target <= 0) {
            $existing?->delete();

            return $cart->refresh();
        }

        if ($target > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => __('At most :max of one item per order.', ['max' => self::MAX_QUANTITY])]);
        }

        $variant = $this->catalog->sellable([$variantUlid])[$variantUlid] ?? null;

        if ($variant === null || ! $variant->purchasable) {
            throw ValidationException::withMessages(['variant_id' => __('This item is not available.')]);
        }

        if (! $variant->allowBackorder) {
            $available = $this->stock->available([$variant->id])[$variant->id] ?? 0;

            if ($target > $available) {
                throw InsufficientStock::for($variant->sku, $target, $available);
            }
        }

        CartItem::query()->updateOrCreate(
            ['cart_id' => $cart->id, 'variant_ulid' => $variantUlid],
            ['quantity' => $target],
        );

        $cart->forceFill(['expires_at' => now()->addDays(CreateCart::GUEST_TTL_DAYS)])->save();

        return $cart->refresh();
    }
}
