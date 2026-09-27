<?php

declare(strict_types=1);

namespace Modules\Checkout\Providers;

use Modules\Support\Providers\ModuleServiceProvider;

/**
 * Orchestration only: Checkout owns no tables and talks to Cart, Pricing, Inventory, Orders
 * and Payments through their contracts.
 */
final class CheckoutServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Checkout';
    }
}
