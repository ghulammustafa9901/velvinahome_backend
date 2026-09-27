<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Categories;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Catalog\Models\Category;

final class ListCategoriesPage extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(CategoryResource::fields())
                ->using(fn (array $data): Category => CategoryResource::save(null, $data)),
        ];
    }
}
