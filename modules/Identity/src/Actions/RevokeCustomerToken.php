<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Laravel\Sanctum\PersonalAccessToken;
use Modules\Identity\Models\Customer;
use Modules\Support\Contracts\Action;

final class RevokeCustomerToken implements Action
{
    public function handle(Customer $customer): void
    {
        $token = $customer->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
