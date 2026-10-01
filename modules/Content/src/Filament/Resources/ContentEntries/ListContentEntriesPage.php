<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\ContentEntries;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Models\ContentEntry;

final class ListContentEntriesPage extends ListRecords
{
    protected static string $resource = ContentEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(ContentEntryResource::fields())
                ->using(fn (array $data): ContentEntry => ContentEntryResource::save(null, $data)),
        ];
    }
}
