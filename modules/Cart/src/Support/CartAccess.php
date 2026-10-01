<?php

declare(strict_types=1);

namespace Modules\Cart\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Cart\Contracts\Carts;
use Modules\Cart\Data\CartData;
use Modules\Cart\Data\CartReminder;
use Modules\Cart\Models\Cart;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\PricedLine;
use Modules\Pricing\Data\PricingRequest;
use Modules\Support\ValueObjects\Money;

final class CartAccess implements Carts
{
    public function __construct(private readonly CartPricer $pricer) {}

    public function rememberEmail(string $cartUlid, string $email): void
    {
        $email = mb_strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            return;
        }

        // Without touching updated_at: typing an email is not cart activity.
        Cart::query()->where('ulid', $cartUlid)->where('status', 'active')->toBase()->update(['email' => $email]);
    }

    public function reminder(string $cartUlid): ?CartReminder
    {
        $cart = Cart::query()->where('ulid', $cartUlid)->where('status', 'active')->whereNull('recovered_at')->first();
        $token = $cart === null ? null : RecoveryToken::for($cart);

        if ($cart === null || $token === null || $cart->email === null || $cart->lines() === []) {
            return null;
        }

        $priced = $this->pricer->price(new PricingRequest($cart->lines(), $cart->currency, $cart->coupon_code, $cart->customer_id));

        if ($priced->lines === []) {
            return null;
        }

        return new CartReminder(
            cartUlid: $cart->ulid,
            email: $cart->email,
            recoveryToken: $token,
            lines: array_map(fn (PricedLine $line): array => [
                'title' => $line->variant->productTitle,
                'variant_title' => $line->variant->variantTitle,
                'quantity' => $line->quantity,
                'total' => $line->total,
                'image_url' => $line->variant->imageUrl,
            ], $priced->lines),
            total: Money::sum(array_map(fn (PricedLine $line): Money => $line->total, $priced->lines), $cart->currency),
        );
    }

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
