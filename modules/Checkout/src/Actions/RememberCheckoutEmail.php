<?php

declare(strict_types=1);

namespace Modules\Checkout\Actions;

use Modules\Cart\Contracts\Carts;
use Modules\Support\Contracts\Action;

/**
 * The email a guest typed at checkout stays with their cart, so an abandoned-cart reminder
 * can reach them (only when the brand has reminders switched on).
 */
final class RememberCheckoutEmail implements Action
{
    public function __construct(private readonly Carts $carts) {}

    public function handle(string $cartUlid, ?int $customerId, ?string $email): void
    {
        if ($customerId === null && $email !== null && trim($email) !== '') {
            $this->carts->rememberEmail($cartUlid, $email);
        }
    }
}
