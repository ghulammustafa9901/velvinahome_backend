<?php

declare(strict_types=1);

namespace Modules\Cart\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Cart\Models\Cart;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\PricingRequest;
use Modules\Support\Contracts\Action;

/**
 * Attaches a coupon only if the pricing pipeline accepts it for this cart now; the reason is
 * returned when it does not. A null code removes the coupon.
 */
final class ApplyCartCoupon implements Action
{
    public function __construct(private readonly CartPricer $pricer) {}

    public function handle(Cart $cart, ?string $code, ?string $email = null): Cart
    {
        if ($code === null || trim($code) === '') {
            $cart->forceFill(['coupon_code' => null])->save();

            return $cart;
        }

        $priced = $this->pricer->price(new PricingRequest($cart->lines(), $cart->currency, $code, $cart->customer_id, $email));

        if ($priced->couponError !== null) {
            throw ValidationException::withMessages(['code' => $priced->couponError]);
        }

        $cart->forceFill(['coupon_code' => strtoupper(trim($code))])->save();

        return $cart;
    }
}
