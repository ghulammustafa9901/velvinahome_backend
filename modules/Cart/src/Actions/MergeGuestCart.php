<?php

declare(strict_types=1);

namespace Modules\Cart\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartItem;
use Modules\Support\Contracts\Action;

/**
 * On sign-in, the guest cart's lines move into the customer's cart (quantities add up, capped)
 * and the guest cart is closed. The guest cart must be proven by its token first.
 */
final class MergeGuestCart implements Action
{
    public function __construct(
        private readonly CreateCart $createCart,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(Cart $guest, int $customerId): Cart
    {
        return $this->db->connection()->transaction(function () use ($guest, $customerId): Cart {
            $target = $this->createCart->handle($customerId)['cart'];

            if ($target->id === $guest->id) {
                return $target;
            }

            foreach ($guest->loadMissing('items')->items as $item) {
                $line = CartItem::query()->firstOrNew(['cart_id' => $target->id, 'variant_ulid' => $item->variant_ulid]);
                $line->quantity = min(SetCartItem::MAX_QUANTITY, ($line->exists ? $line->quantity : 0) + $item->quantity);
                $line->save();
            }

            if ($target->coupon_code === null && $guest->coupon_code !== null) {
                $target->coupon_code = $guest->coupon_code;
                $target->save();
            }

            $guest->forceFill(['status' => 'merged'])->save();

            return $target->refresh();
        });
    }
}
