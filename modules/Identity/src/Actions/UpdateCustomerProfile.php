<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Modules\Identity\Data\CustomerProfileData;
use Modules\Identity\Models\Customer;
use Modules\Support\Contracts\Action;

/**
 * A customer edits their own name, phone and marketing consent. The email address is the
 * sign-in identity and is not changed here.
 */
final class UpdateCustomerProfile implements Action
{
    public function handle(Customer $customer, CustomerProfileData $data): Customer
    {
        $customer->fill([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'phone' => $data->phone,
            'accepts_marketing' => $data->acceptsMarketing,
        ])->save();

        return $customer;
    }
}
