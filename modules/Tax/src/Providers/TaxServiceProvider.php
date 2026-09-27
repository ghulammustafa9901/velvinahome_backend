<?php

declare(strict_types=1);

namespace Modules\Tax\Providers;

use Modules\Support\Providers\ModuleServiceProvider;
use Modules\Tax\Contracts\TaxCalculator;
use Modules\Tax\Support\RateTableTaxCalculator;

final class TaxServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Tax';
    }

    protected function registerModule(): void
    {
        // Not scoped: the rate cache lives inside one instance, so it can never outlive a
        // tenant switch within the same request or job.
        $this->app->bind(TaxCalculator::class, RateTableTaxCalculator::class);
    }
}
