<?php

declare(strict_types=1);

namespace Modules\Customers\Support;

use Modules\Customers\Actions\SaveAddress;
use Modules\Customers\Contracts\AddressImport;
use Modules\Customers\Data\AddressData;
use Modules\Customers\Models\CustomerAddress;

final class ActionAddressImport implements AddressImport
{
    public function __construct(private readonly SaveAddress $save) {}

    public function add(int $customerId, AddressData $address, bool $defaultShipping): bool
    {
        $exists = CustomerAddress::query()
            ->where('customer_id', $customerId)
            ->whereRaw('lower(line1) = ?', [mb_strtolower($address->line1)])
            ->whereRaw('lower(city) = ?', [mb_strtolower($address->city)])
            ->where('phone', $address->phone)
            ->exists();

        if ($exists) {
            return false;
        }

        $this->save->handle($customerId, null, $address, 'Imported', $defaultShipping);

        return true;
    }
}
