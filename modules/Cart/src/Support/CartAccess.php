<?php

declare(strict_types=1);

namespace Modules\Cart\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Cart\Contracts\Carts;
use Modules\Cart\Data\CartData;
use Modules\Cart\Models\Cart;

final class CartAccess implements Carts
{
    public function accessible(string $cartUlid, ?int $customerId, ?string $token): CartData
    {
        $cart = $this->find($cartUlid, $customerId, $token);

        return new CartData($cart->ulid, $cart->customer_id, $cart->currency, $cart->lines(), $cart->coupon_code);
    }

    public function markConverted(string $cartUlid, string $orderUlid): void
    {
        Cart::query()->where('ulid', $cartUlid)->where('status', 'active')->lockForUpdate()->firstOrFail()
            ->forceFill(['status' => 'converted', 'order_ulid' => $orderUlid])->save();
    }

    /**
     * Customer carts need that customer's token; guest carts need their own secret. The two
     * checks are constant-time and indistinguishable to the caller: anything else is a 404.
     */
    public function find(string $cartUlid, ?int $customerId, ?string $token): Cart
    {
        $cart = Cart::query()->where('ulid', $cartUlid)->where('status', 'active')->first();

        $allowed = $cart !== null && ($cart->customer_id !== null
            ? $customerId !== null && $cart->customer_id === $customerId
            : $token !== null && $cart->token_hash !== null && hash_equals($cart->token_hash, hash('sha256', $token)));

        if (! $allowed || $cart === null) {
            throw (new ModelNotFoundException)->setModel(Cart::class, [$cartUlid]);
        }

        return $cart;
    }
}
