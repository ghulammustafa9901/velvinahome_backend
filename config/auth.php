<?php

declare(strict_types=1);

use Modules\Identity\Models\Customer;
use Modules\Identity\Models\StaffUser;
use Modules\Platform\Models\PlatformUser;

/*
|--------------------------------------------------------------------------
| Three populations, three guards, three tables — never mixed (spec §6.2)
|--------------------------------------------------------------------------
|
|   platform  platform_users (landlord DB)   session, platform panel only
|   staff     users          (tenant DB)     session, that brand's admin only
|   customer  customers      (tenant DB)     Sanctum token, that brand's API only
|
| A staff or customer provider always reads the *current tenant's* database, so a
| credential from one brand cannot even be looked up on another.
|
*/

return [

    'defaults' => [
        'guard' => 'staff',
        'passwords' => 'staff',
    ],

    'guards' => [
        'platform' => [
            'driver' => 'session',
            'provider' => 'platform_users',
        ],

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
        'platform_users' => [
            'driver' => 'eloquent',
            'model' => PlatformUser::class,
        ],

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
