<?php

declare(strict_types=1);

namespace Modules\Pricing\Filament\Resources\Promotions;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Pricing\Models\Promotion;

final class ListPromotionsPage extends ListRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(PromotionResource::fields())
                ->using(fn (array $data): Promotion => PromotionResource::save(null, $data)),
        ];
    }
}
