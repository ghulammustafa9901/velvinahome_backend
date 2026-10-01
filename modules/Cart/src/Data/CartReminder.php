<?php

declare(strict_types=1);

namespace Modules\Cart\Data;

use Modules\Support\ValueObjects\Money;

/**
 * What an abandoned-cart email needs: who to write to, what is still in the cart (priced
 * now, with any running promotion), and the secret for the "return to your cart" link.
 */
final readonly class CartReminder
{
    /**
     * @param  list<array{title: string, variant_title: string|null, quantity: int, total: Money, image_url: string|null}>  $lines
     */
    public function __construct(
        public string $cartUlid,
        public string $email,
        public string $recoveryToken,
        public array $lines,
        public Money $total,
    ) {}
}
