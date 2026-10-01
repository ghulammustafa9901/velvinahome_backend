<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\ContentTypes;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Actions\SaveContentType;
use Modules\Content\Models\ContentType;
use Modules\Content\Support\ContentFields;
use UnitEnum;

/**
 * Staff-defined content types: a key (its API address /content/{key}), a name, and fields.
 */
final class ContentTypeResource extends Resource
{
    protected static ?string $model = ContentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content types';

    protected static ?int $navigationSort = 5;

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return self::actor()?->can('content.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (ContentType $record): string => '/content/'.$record->key),
                TextColumn::make('fields')->formatStateUsing(fn (ContentType $record): string => implode(', ', array_column($record->fields, 'label')) ?: '—')->wrap(),
                TextColumn::make('entries_count')->counts('entries')->label('Entries'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (ContentType $record): array => ['key' => $record->key, 'name' => $record->name, 'fields' => $record->fields])
                    ->schema(self::fields(editing: true))
                    ->action(fn (ContentType $record, array $data) => app(SaveContentType::class)->handle($record, $record->key, (string) $data['name'], array_values((array) ($data['fields'] ?? [])), self::actor())),
            ]);
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(bool $editing = false): array
    {
        return [
            TextInput::make('key')->required()->maxLength(64)->disabled($editing)->regex('/^[a-z][a-z0-9-]{1,63}$/')
                ->helperText('Its address in the API, e.g. "faq" or "store-locations". Cannot change later.'),
            TextInput::make('name')->required()->maxLength(120),
            Repeater::make('fields')->maxItems(ContentFields::MAX_FIELDS)->columns(4)->schema([
                TextInput::make('key')->required()->maxLength(40)->regex('/^[a-z][a-z0-9_]*$/'),
                TextInput::make('label')->required()->maxLength(80),
                Select::make('kind')->required()->options(array_map(fn (array $kind): string => $kind[0], ContentFields::KINDS)),
                Toggle::make('required')->inline(false),
            ]),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListContentTypesPage::route('/')];
    }
}
