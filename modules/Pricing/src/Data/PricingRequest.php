<?php

declare(strict_types=1);

namespace Modules\Pricing\Data;

use Modules\Customers\Data\AddressData;

/**
 * What to price. Only ids and quantities come from the client; every price is looked up.
 */
final readonly class PricingRequest
{
    /**
     * @param  array<string, int>  $lines  variant ULID => quantity
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public ?string $couponCode = null,
        public ?int $customerId = null,
        public ?string $email = null,
        public ?AddressData $shippingAddress = null,
        public ?string $shippingMethodCode = null,
    ) {}
}
