<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Modules\Customers\Data\AddressData;
use Modules\Shipping\Data\ShippingOptionData;
use Modules\Support\ValueObjects\Money;

interface ShippingRates
{
    /**
     * Methods that deliver to the address, priced for this cart. Empty when the store does
     * not ship there.
     *
     * @return list<ShippingOptionData>
     */
    public function options(AddressData $address, Money $subtotal, int $weightGrams): array;
}
