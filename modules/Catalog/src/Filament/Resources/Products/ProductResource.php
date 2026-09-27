<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Filament\Resources\Products\Pages\CreateProductPage;
use Modules\Catalog\Filament\Resources\Products\Pages\EditProductPage;
use Modules\Catalog\Filament\Resources\Products\Pages\ListProductsPage;
use Modules\Catalog\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use UnitEnum;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

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
        return StaffAccess::can('products.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The descriptive fields shared by create and edit.
     *
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function detailFields(): array
    {
        return [
            TextInput::make('title')->required()->maxLength(255),
            Select::make('brand')->searchable()
                ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'ulid')->all()),
            Select::make('categories')->multiple()->searchable()
                ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'ulid')->all())
                ->helperText('The first category is the primary one (breadcrumbs, feeds).'),
            Textarea::make('short_description')->maxLength(500)->rows(2),
            Textarea::make('description')->rows(8),
            TagsInput::make('tags'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...self::detailFields(),
            TextInput::make('slug')->required()->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->helperText('Changing it keeps the old URL working with a 301 redirect.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['defaultVariant', 'brand'])->withCount('variants'))
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(50),
                TextColumn::make('status')->badge()->color(fn (Product $record): string => match ($record->status) {
                    ProductStatus::Active => 'success',
                    ProductStatus::Draft => 'gray',
                    ProductStatus::Archived => 'warning',
                }),
                TextColumn::make('price')->label('Price')
                    ->state(fn (Product $record): string => $record->defaultVariant?->price->format() ?? '—'),
                TextColumn::make('defaultVariant.sku')->label('SKU')->searchable(),
                TextColumn::make('variants_count')->label('Variants'),
                TextColumn::make('brand.name')->placeholder('—'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ProductStatus::cases())->mapWithKeys(
                    fn (ProductStatus $status): array => [$status->value => ucfirst($status->value)],
                )->all()),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [VariantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductsPage::route('/'),
            'create' => CreateProductPage::route('/create'),
            'edit' => EditProductPage::route('/{record}/edit'),
        ];
    }
}
