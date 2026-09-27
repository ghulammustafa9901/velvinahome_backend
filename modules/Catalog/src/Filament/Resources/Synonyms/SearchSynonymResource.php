<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Synonyms;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Actions\SaveSearchSynonym;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\SearchSynonym;
use UnitEnum;

/**
 * Search synonyms for this store — local and Roman Urdu spellings shoppers actually type.
 */
final class SearchSynonymResource extends Resource
{
    protected static ?string $model = SearchSynonym::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Search synonyms';

    protected static ?string $slug = 'search-synonyms';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return StaffAccess::can('seo.manage');
    }

    public static function canCreate(): bool
    {
        return StaffAccess::can('seo.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TagsInput::make('terms')->required()->helperText('e.g. takia, cushion, pillow — all find each other.'),
            TextInput::make('root')->maxLength(100)
                ->helperText('Optional. If set, the terms find the root but not the other way round (e.g. lown → lawn).'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('terms')->badge()->separator(','),
                TextColumn::make('root')->placeholder('multi-way'),
                TextColumn::make('updated_at')->since(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (SearchSynonym $record): array => ['terms' => $record->terms, 'root' => $record->root])
                    ->schema(self::fields())
                    ->action(fn (SearchSynonym $record, array $data) => app(SaveSearchSynonym::class)
                        ->handle($record, array_values($data['terms'] ?? []), $data['root'] ?? null, StaffAccess::actor())),
                Action::make('delete')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->action(fn (SearchSynonym $record) => app(SaveSearchSynonym::class)->handle($record, [], null, StaffAccess::actor())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSearchSynonymsPage::route('/')];
    }
}
