<?php

declare(strict_types=1);

namespace Modules\Orders\Filament\Resources\Orders;

use Filament\Resources\Pages\ListRecords;

final class ListOrdersPage extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
