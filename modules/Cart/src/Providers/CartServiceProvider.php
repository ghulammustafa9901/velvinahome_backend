<?php

declare(strict_types=1);

namespace Modules\Cart\Providers;

use Modules\Cart\Contracts\Carts;
use Modules\Cart\Support\CartAccess;
use Modules\Support\Providers\ModuleServiceProvider;

final class CartServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Cart';
    }

    protected function registerModule(): void
    {
        $this->app->bind(Carts::class, CartAccess::class);
    }
}
