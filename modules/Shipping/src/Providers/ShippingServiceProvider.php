<?php

declare(strict_types=1);

namespace Modules\Shipping\Providers;

use Modules\Shipping\Contracts\Couriers;
use Modules\Shipping\Contracts\ShippingRates;
use Modules\Shipping\Seeders\DefaultShippingSeeder;
use Modules\Shipping\Support\CourierAdapters;
use Modules\Shipping\Support\DatabaseCouriers;
use Modules\Shipping\Support\ZoneShippingRates;
use Modules\Support\Providers\ModuleServiceProvider;

final class ShippingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Shipping';
    }

    protected function registerModule(): void
    {
        $this->app->bind(ShippingRates::class, ZoneShippingRates::class);
        $this->app->bind(Couriers::class, DatabaseCouriers::class);
        $this->app->singleton(CourierAdapters::class);
    }

    protected function tenantSeeders(): array
    {
        return [DefaultShippingSeeder::class];
    }
}
