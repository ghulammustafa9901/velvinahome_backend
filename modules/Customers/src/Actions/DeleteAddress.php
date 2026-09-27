<?php

declare(strict_types=1);

namespace Modules\Customers\Actions;

use Modules\Customers\Models\CustomerAddress;
use Modules\Support\Contracts\Action;

final class DeleteAddress implements Action
{
    public function handle(int $customerId, string $addressUlid): void
    {
        CustomerAddress::query()->where('customer_id', $customerId)->where('ulid', $addressUlid)->firstOrFail()->delete();
    }
}
