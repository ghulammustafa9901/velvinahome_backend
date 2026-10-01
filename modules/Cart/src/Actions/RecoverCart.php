<?php

declare(strict_types=1);

namespace Modules\Cart\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Cart\Support\RecoveryToken;
use Modules\Support\Contracts\Action;

/**
 * The reminder email's link: exchanges the recovery secret for a fresh cart token for this
 * browser. The token rotates, so the link opens the cart in one browser at a time; a wrong,
 * old or reused-after-purchase link is simply not found.
 */
final class RecoverCart implements Action
{
    /**
     * @return array{cart: Cart, token: string}
     */
    public function handle(string $cartUlid, string $recoveryToken): array
    {
        $cart = Cart::query()->where('ulid', $cartUlid)->where('status', 'active')->whereNull('customer_id')->first();

        if ($cart === null || ! RecoveryToken::valid($cart, $recoveryToken)) {
            throw (new ModelNotFoundException)->setModel(Cart::class, [$cartUlid]);
        }

        $token = Str::random(48);
        $cart->forceFill([
            'token_hash' => hash('sha256', $token),
            'recovered_at' => $cart->recovered_at ?? Carbon::now(),
            'expires_at' => Carbon::now()->addDays(CreateCart::GUEST_TTL_DAYS),
        ])->save();

        return ['cart' => $cart, 'token' => $token];
    }
}
