<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Brands;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Catalog\Actions\SaveBrand;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\Brand;

final class ListBrandsPage extends ListRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(BrandResource::fields())
                ->using(fn (array $data): Brand => app(SaveBrand::class)->handle(
                    null, (string) $data['name'], $data['description'] ?? null, (bool) ($data['is_visible'] ?? true), StaffAccess::actor(),
                )),
        ];
    }
}
