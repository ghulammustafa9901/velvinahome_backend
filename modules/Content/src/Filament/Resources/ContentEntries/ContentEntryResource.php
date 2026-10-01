<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\ContentEntries;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Actions\SaveContentEntry;
use Modules\Content\Models\ContentEntry;
use Modules\Content\Models\ContentType;
use UnitEnum;

/**
 * Entries of every content type; the form shows the chosen type's fields.
 */
final class ContentEntryResource extends Resource
{
    protected static ?string $model = ContentEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content entries';

    protected static ?string $modelLabel = 'entry';

    protected static ?int $navigationSort = 6;

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
            ->modifyQueryUsing(fn ($query) => $query->with('type'))
            ->columns([
                TextColumn::make('title')->searchable()->weight('bold'),
                TextColumn::make('type.name')->label('Type')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('position')->numeric()->sortable(),
            ])
            ->filters([SelectFilter::make('content_type_id')->label('Type')->options(fn (): array => ContentType::query()->orderBy('name')->pluck('name', 'id')->all())])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (ContentEntry $record): array => [
                        'content_type_id' => $record->content_type_id, 'title' => $record->title, 'slug' => $record->slug,
                        'values' => $record->values, 'published' => $record->status === 'published', 'position' => $record->position,
                    ])
                    ->schema(self::fields())
                    ->action(fn (ContentEntry $record, array $data) => self::save($record, $data)),
            ])
            ->defaultSort('position');
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|Field>
     */
    public static function fields(): array
    {
        return [
            Select::make('content_type_id')->label('Type')->required()->live()
                ->options(fn (): array => ContentType::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->label('Address')->maxLength(120)->helperText('Leave empty to use the title.'),
            Group::make(fn (Get $get): array => self::valueFields($get('content_type_id'))),
            TextInput::make('position')->numeric()->integer()->default(0),
            Toggle::make('published'),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function valueFields(mixed $typeId): array
    {
        $type = is_numeric($typeId) ? ContentType::query()->find((int) $typeId) : null;

        return $type === null ? [] : array_map(function (array $field): Field {
            $name = 'values.'.$field['key'];

            $component = match ($field['kind']) {
                'textarea' => Textarea::make($name)->rows(4)->maxLength(5000),
                'number' => TextInput::make($name)->numeric()->integer(),
                'boolean' => Toggle::make($name),
                'date' => DatePicker::make($name),
                default => TextInput::make($name)->maxLength($field['kind'] === 'text' ? 300 : 2048),
            };

            return $component->label($field['label'])->required($field['required'] && $field['kind'] !== 'boolean');
        }, $type->fields);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?ContentEntry $entry, array $data): ContentEntry
    {
        $type = ContentType::query()->findOrFail((int) $data['content_type_id']);

        return app(SaveContentEntry::class)->handle(
            $type,
            $entry !== null && $entry->content_type_id === $type->id ? $entry : null,
            (string) $data['title'],
            $data['slug'] ?? null,
            (array) ($data['values'] ?? []),
            (bool) ($data['published'] ?? false),
            (int) ($data['position'] ?? 0),
            self::actor(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ListContentEntriesPage::route('/')];
    }
}
