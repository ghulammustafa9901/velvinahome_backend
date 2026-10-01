<?php

declare(strict_types=1);

namespace Modules\Cart\Support;

use Modules\Cart\Models\Cart;

/**
 * The secret in a reminder email's "return to your cart" link. Derived, not stored (like order
 * access tokens, ADR-0015): an HMAC of the cart and the moment its reminder was sent, so it is
 * unique per reminder and nothing to leak from the database.
 */
final class RecoveryToken
{
    public const VALID_DAYS = 7;

    public static function for(Cart $cart): ?string
    {
        if ($cart->reminded_at === null) {
            return null;
        }

        return hash_hmac('sha256', 'cart-recovery|'.$cart->ulid.'|'.$cart->reminded_at->getTimestamp(), (string) config('app.key'));
    }

    public static function valid(Cart $cart, string $token): bool
    {
        $expected = self::for($cart);

        return $expected !== null
            && $cart->reminded_at !== null
            && $cart->reminded_at->greaterThan(now()->subDays(self::VALID_DAYS))
            && hash_equals($expected, $token);
    }
}
