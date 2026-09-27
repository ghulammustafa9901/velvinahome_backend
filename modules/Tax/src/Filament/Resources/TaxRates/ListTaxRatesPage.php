<?php

declare(strict_types=1);

namespace Modules\Tax\Filament\Resources\TaxRates;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Tax\Models\TaxRate;

final class ListTaxRatesPage extends ListRecords
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(TaxRateResource::fields())
                ->using(fn (array $data): TaxRate => TaxRateResource::save(null, $data)),
        ];
    }
}
