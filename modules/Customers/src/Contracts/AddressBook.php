<?php

declare(strict_types=1);

namespace Modules\Customers\Contracts;

use Modules\Customers\Data\AddressData;

interface AddressBook
{
    /**
     * A customer's saved address — only ever that customer's (no IDOR through checkout).
     */
    public function find(int $customerId, string $addressUlid): ?AddressData;
}
