<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Brands;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Actions\SaveBrand;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\Brand;
use UnitEnum;

/**
 * Product brands (labels/manufacturers) of this store. Create and edit run SaveBrand.
 */
final class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

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
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug'),
                IconColumn::make('is_visible')->boolean()->label('Visible'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => StaffAccess::can('products.manage'))
                    ->fillForm(fn (Brand $record): array => $record->only(['name', 'description', 'is_visible']))
                    ->schema(self::fields())
                    ->action(fn (Brand $record, array $data) => app(SaveBrand::class)->handle(
                        $record, (string) $data['name'], $data['description'] ?? null, (bool) ($data['is_visible'] ?? true), StaffAccess::actor(),
                    )),
            ])
            ->defaultSort('name');
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(120),
            Textarea::make('description'),
            Toggle::make('is_visible')->default(true),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListBrandsPage::route('/')];
    }
}
