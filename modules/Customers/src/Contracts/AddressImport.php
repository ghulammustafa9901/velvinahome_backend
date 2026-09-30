<?php

declare(strict_types=1);

namespace Modules\Customers\Contracts;

use Modules\Customers\Data\AddressData;

/**
 * Saved addresses of migrated customers. The same address is never stored twice, so an
 * import can be run again safely.
 */
interface AddressImport
{
    /**
     * @return bool false when the customer already had this address
     */
    public function add(int $customerId, AddressData $address, bool $defaultShipping): bool;
}
