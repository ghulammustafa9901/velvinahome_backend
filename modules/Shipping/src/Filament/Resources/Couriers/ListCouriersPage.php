<?php

declare(strict_types=1);

namespace Modules\Shipping\Filament\Resources\Couriers;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Shipping\Models\Courier;

final class ListCouriersPage extends ListRecords
{
    protected static string $resource = CourierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(CourierResource::fields())
                ->using(fn (array $data): Courier => CourierResource::save(null, $data)),
        ];
    }
}
