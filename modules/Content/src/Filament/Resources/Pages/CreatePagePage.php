<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Actions\SavePage;

final class CreatePagePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SavePage::class)->handle(
            null,
            (string) $data['title'],
            $data['slug'] ?? null,
            array_values((array) ($data['sections'] ?? [])),
            $data['meta_title'] ?? null,
            $data['meta_description'] ?? null,
            PageResource::actor(),
        );
    }
}
