<?php

declare(strict_types=1);

namespace Modules\Checkout\Support;

/**
 * The guest's secret for viewing their order. Derived (HMAC with the app key) rather than
 * random so an idempotent replay of the placement can hand back the same token; only its
 * SHA-256 is stored with the order.
 */
final class OrderAccessToken
{
    public static function for(string $orderUlid): string
    {
        $key = (string) config('app.key');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'order-access|'.$orderUlid, $key, true)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
