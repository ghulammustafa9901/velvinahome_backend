<?php

declare(strict_types=1);

namespace Modules\Customers\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Catalog\Contracts\ProductDirectory;
use Modules\Customers\Models\WishlistItem;
use Modules\Support\Contracts\Action;

/**
 * Idempotent: adding what is already there returns the existing item.
 */
final class AddToWishlist implements Action
{
    public const MAX_ITEMS = 200;

    public function __construct(private readonly ProductDirectory $products) {}

    public function handle(int $customerId, string $productUlid, ?string $variantUlid): WishlistItem
    {
        if (! $this->products->isLive($productUlid)) {
            throw ValidationException::withMessages(['product_id' => __('This product is not available.')]);
        }

        $existing = WishlistItem::query()->where('customer_id', $customerId)->where('product_ulid', $productUlid)
            ->where('variant_ulid', $variantUlid)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (WishlistItem::query()->where('customer_id', $customerId)->count() >= self::MAX_ITEMS) {
            throw ValidationException::withMessages(['product_id' => __('Your wishlist is full.')]);
        }

        return WishlistItem::query()->create(['customer_id' => $customerId, 'product_ulid' => $productUlid, 'variant_ulid' => $variantUlid]);
    }
}
