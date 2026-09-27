<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament\Resources\Locations;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Inventory\Actions\CreateLocation;
use Modules\Inventory\Data\LocationData;
use Modules\Inventory\Filament\InventoryForms;
use Modules\Inventory\Models\Location;

final class ListLocationsPage extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(LocationResource::fields())
                ->using(fn (array $data): Location => app(CreateLocation::class)->handle(LocationData::validateAndCreate([
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'type' => $data['type'],
                    'priority' => (int) ($data['priority'] ?? 0),
                    'isDefault' => (bool) ($data['is_default'] ?? false),
                ]), InventoryForms::actor())),
        ];
    }
}
