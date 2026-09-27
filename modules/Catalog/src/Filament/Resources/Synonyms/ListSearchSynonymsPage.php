<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Synonyms;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Catalog\Actions\SaveSearchSynonym;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\SearchSynonym;

final class ListSearchSynonymsPage extends ListRecords
{
    protected static string $resource = SearchSynonymResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(SearchSynonymResource::fields())
                ->using(fn (array $data): ?SearchSynonym => app(SaveSearchSynonym::class)
                    ->handle(null, array_values($data['terms'] ?? []), $data['root'] ?? null, StaffAccess::actor())),
        ];
    }
}
