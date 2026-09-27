<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament\Resources\Movements;

use Filament\Resources\Pages\ListRecords;

final class ListMovementsPage extends ListRecords
{
    protected static string $resource = MovementResource::class;
}
