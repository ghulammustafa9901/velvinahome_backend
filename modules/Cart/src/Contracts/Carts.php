<?php

declare(strict_types=1);

namespace Modules\Cart\Contracts;

use Modules\Cart\Data\CartData;
use Modules\Cart\Data\CartReminder;

interface Carts
{
    /**
     * An active cart the caller may use: a guest cart with its secret token, or the signed-in
     * customer's own cart. Anything else is not found (spec §8: no IDOR).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function accessible(string $cartUlid, ?int $customerId, ?string $token): CartData;

    /**
     * Close the cart once its order exists (same transaction as the order).
     */
    public function markConverted(string $cartUlid, string $orderUlid): void;

    /**
     * Remember the email typed at checkout, for an abandoned-cart reminder.
     */
    public function rememberEmail(string $cartUlid, string $email): void;

    /**
     * The reminder for a cart that is still abandoned (active, reminded, not yet recovered,
     * not empty), or null when there is nothing to remind about any more.
     */
    public function reminder(string $cartUlid): ?CartReminder;
}
