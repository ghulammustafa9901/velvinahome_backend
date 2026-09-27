<?php

declare(strict_types=1);

namespace Modules\Cart\Data;

final readonly class CartData
{
    /**
     * @param  array<string, int>  $lines  variant ULID => quantity
     */
    public function __construct(
        public string $ulid,
        public ?int $customerId,
        public string $currency,
        public array $lines,
        public ?string $couponCode,
    ) {}
}
