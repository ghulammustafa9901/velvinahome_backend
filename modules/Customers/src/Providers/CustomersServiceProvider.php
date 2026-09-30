<?php

declare(strict_types=1);

namespace Modules\Customers\Providers;

use Modules\Customers\Contracts\AddressBook;
use Modules\Customers\Contracts\AddressImport;
use Modules\Customers\Support\ActionAddressImport;
use Modules\Customers\Support\EloquentAddressBook;
use Modules\Support\Providers\ModuleServiceProvider;

final class CustomersServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Customers';
    }

    protected function registerModule(): void
    {
        $this->app->bind(AddressBook::class, EloquentAddressBook::class);
        $this->app->bind(AddressImport::class, ActionAddressImport::class);
    }
}
