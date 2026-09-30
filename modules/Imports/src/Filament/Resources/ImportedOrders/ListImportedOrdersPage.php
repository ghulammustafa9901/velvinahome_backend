<?php

declare(strict_types=1);

namespace Modules\Imports\Filament\Resources\ImportedOrders;

use Filament\Resources\Pages\ListRecords;

final class ListImportedOrdersPage extends ListRecords
{
    protected static string $resource = ImportedOrderResource::class;
}
