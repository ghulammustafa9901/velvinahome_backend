<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Categories;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Actions\SaveCategory;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\Category;
use UnitEnum;

/**
 * The category tree. Moving a category moves its subtree (SaveCategory maintains the
 * closure table) and renaming its slug leaves a 301.
 */
final class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return StaffAccess::can('products.view');
    }

    public static function canCreate(): bool
    {
        return StaffAccess::can('products.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->placeholder('—'),
                TextColumn::make('slug'),
                TextColumn::make('position')->sortable(),
                IconColumn::make('is_visible')->boolean()->label('Visible'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => StaffAccess::can('products.manage'))
                    ->fillForm(fn (Category $record): array => $record->only(['name', 'slug', 'description', 'is_visible', 'position'])
                        + ['parent' => $record->parent?->ulid])
                    ->schema(self::fields(withSlug: true))
                    ->action(fn (Category $record, array $data) => self::save($record, $data)),
            ])
            ->defaultSort('position');
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(bool $withSlug = false): array
    {
        return array_values(array_filter([
            TextInput::make('name')->required()->maxLength(120),
            $withSlug ? TextInput::make('slug')->required()->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')->helperText('Changing it keeps the old URL working with a 301.') : null,
            Select::make('parent')->label('Parent category')->searchable()
                ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'ulid')->all()),
            Textarea::make('description'),
            TextInput::make('position')->numeric()->default(0),
            Toggle::make('is_visible')->default(true),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Category $category, array $data): Category
    {
        return app(SaveCategory::class)->handle(
            $category,
            (string) $data['name'],
            $data['parent'] ?? null,
            $data['slug'] ?? null,
            $data['description'] ?? null,
            (bool) ($data['is_visible'] ?? true),
            (int) ($data['position'] ?? 0),
            StaffAccess::actor(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ListCategoriesPage::route('/')];
    }
}
