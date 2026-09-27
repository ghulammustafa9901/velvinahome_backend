<?php

declare(strict_types=1);

namespace Modules\Orders\Data;

use Modules\Customers\Data\AddressData;
use Modules\Support\ValueObjects\Money;

/**
 * Everything Checkout has verified and priced, handed to Orders to record. Orders trusts
 * none of it more than it must: the totals are checked again by a database constraint.
 */
final readonly class NewOrder
{
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    /**
     * @param  list<NewOrderLine>  $lines
     * @param  self::STATUS_*  $initialStatus
     */
    public function __construct(
        public string $ulid,
        public ?int $customerId,
        public string $email,
        public string $phone,
        public string $currency,
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $shipping,
        public Money $tax,
        public Money $total,
        public bool $taxInclusive,
        public AddressData $shippingAddress,
        public ?AddressData $billingAddress,
        public ?string $shippingMethodCode,
        public ?string $shippingMethodName,
        public string $paymentMethodCode,
        public ?string $couponCode,
        public ?string $customerNote,
        public string $initialStatus,
        public string $idempotencyKey,
        public string $requestHash,
        public string $accessTokenHash,
        public ?string $ipAddress,
    ) {}
}
