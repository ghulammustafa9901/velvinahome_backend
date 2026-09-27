<?php

declare(strict_types=1);

namespace Modules\Shipping\Filament\Resources\ShippingMethods;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Shipping\Models\ShippingMethod;

final class ListShippingMethodsPage extends ListRecords
{
    protected static string $resource = ShippingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addZone')->label('New zone')->icon(Heroicon::OutlinedGlobeAsiaAustralia)->color('gray')
                ->schema(ShippingMethodResource::zoneFields())
                ->action(function (array $data): void {
                    ShippingMethodResource::saveZone(null, $data);
                    Notification::make()->title('Zone created — now add a method to it')->success()->send();
                }),
            CreateAction::make()->label('New method')
                ->schema(ShippingMethodResource::fields())
                ->using(fn (array $data): ShippingMethod => ShippingMethodResource::save(null, $data)),
        ];
    }
}
