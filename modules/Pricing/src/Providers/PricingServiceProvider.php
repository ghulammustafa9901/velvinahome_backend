<?php

declare(strict_types=1);

namespace Modules\Pricing\Providers;

use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Contracts\Coupons;
use Modules\Pricing\Support\LedgerCoupons;
use Modules\Pricing\Support\PricingPipeline;
use Modules\Support\Providers\ModuleServiceProvider;

final class PricingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Pricing';
    }

    protected function registerModule(): void
    {
        $this->app->bind(CartPricer::class, PricingPipeline::class);
        $this->app->bind(Coupons::class, LedgerCoupons::class);
    }
}
