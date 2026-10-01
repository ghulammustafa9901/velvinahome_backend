<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Articles;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Models\Article;

final class ListArticlesPage extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->slideOver()
                ->schema(ArticleResource::fields())
                ->using(fn (array $data): Article => ArticleResource::save(null, $data)),
        ];
    }
}
