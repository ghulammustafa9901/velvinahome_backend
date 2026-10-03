<?php

declare(strict_types=1);

namespace Modules\Inventory\Providers;

use Modules\Inventory\Contracts\StockImport;
use Modules\Inventory\Contracts\StockReader;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Inventory\Seeders\DefaultLocationSeeder;
use Modules\Inventory\Support\ActionStockImport;
use Modules\Inventory\Support\ActionStockReservations;
use Modules\Inventory\Support\DatabaseStockReader;
use Modules\Support\Providers\ModuleServiceProvider;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Inventory';
    }

    protected function registerModule(): void
    {
        $this->app->bind(StockReader::class, DatabaseStockReader::class);
        $this->app->bind(StockReservations::class, ActionStockReservations::class);
        $this->app->bind(StockImport::class, ActionStockImport::class);
    }

    protected function appendOnlyTables(): array
    {
        return ['inventory_movements', 'stock_snapshots_daily'];
    }

    protected function defaultSeeders(): array
    {
        return [DefaultLocationSeeder::class];
    }
}
