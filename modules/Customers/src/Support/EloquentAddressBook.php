<?php

declare(strict_types=1);

namespace Modules\Customers\Support;

use Modules\Customers\Contracts\AddressBook;
use Modules\Customers\Data\AddressData;
use Modules\Customers\Models\CustomerAddress;

final class EloquentAddressBook implements AddressBook
{
    public function find(int $customerId, string $addressUlid): ?AddressData
    {
        return CustomerAddress::query()->where('customer_id', $customerId)->where('ulid', $addressUlid)->first()?->toData();
    }
}
