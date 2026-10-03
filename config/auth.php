<?php

declare(strict_types=1);

use Modules\Identity\Models\Customer;
use Modules\Identity\Models\StaffUser;

/*
|--------------------------------------------------------------------------
| Two populations, two guards, two tables — never mixed (spec §6.2)
|--------------------------------------------------------------------------
|
|   staff     users      session, the store admin only
|   customer  customers  Sanctum token, the storefront API only
|
*/

return [

    'defaults' => [
        'guard' => 'staff',
        'passwords' => 'staff',
    ],

    'guards' => [
        'staff' => [
            'driver' => 'session',
            'provider' => 'staff',
        ],

        'customer' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'staff' => [
            'driver' => 'eloquent',
            'model' => StaffUser::class,
        ],

        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],
    ],

    'passwords' => [
        'staff' => [
            'provider' => 'staff',
            'table' => 'password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 3600,

];
