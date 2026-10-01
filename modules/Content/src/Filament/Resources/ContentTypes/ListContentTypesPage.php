<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\ContentTypes;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Actions\SaveContentType;
use Modules\Content\Models\ContentType;

final class ListContentTypesPage extends ListRecords
{
    protected static string $resource = ContentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(ContentTypeResource::fields())
                ->using(fn (array $data): ContentType => app(SaveContentType::class)->handle(null, (string) $data['key'], (string) $data['name'], array_values((array) ($data['fields'] ?? [])), ContentTypeResource::actor())),
        ];
    }
}
