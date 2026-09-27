<?php

declare(strict_types=1);

namespace Modules\Checkout\Data;

use Modules\Customers\Data\AddressData;

final readonly class PlaceOrderInput
{
    public function __construct(
        public string $cartUlid,
        public ?string $cartToken,
        public ?int $customerId,
        public string $email,
        public AddressData $shippingAddress,
        public ?AddressData $billingAddress,
        public string $shippingMethodCode,
        public string $paymentMethodCode,
        public ?string $note,
        public int $expectedTotal,
        public string $idempotencyKey,
        public ?string $ipAddress,
    ) {}

    /**
     * Fingerprint of what was asked for, so a replayed key with a different body is refused.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->cartUlid,
            $this->customerId,
            mb_strtolower($this->email),
            $this->shippingAddress->toColumns(),
            $this->billingAddress?->toColumns(),
            $this->shippingMethodCode,
            $this->paymentMethodCode,
            $this->note,
            $this->expectedTotal,
        ], JSON_THROW_ON_ERROR));
    }
}
