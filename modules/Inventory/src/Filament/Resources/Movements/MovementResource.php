<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament\Resources\Movements;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Filament\InventoryForms;
use Modules\Inventory\Models\InventoryMovement;
use UnitEnum;

/**
 * The ledger, read-only: every stock change, why, by whom, and the balance it left.
 */
final class MovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock ledger';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return InventoryForms::can('inventory.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('sku')->label('SKU')
                    ->state(fn (InventoryMovement $record): string => app(VariantCatalog::class)->findById($record->variant_id)->sku ?? '#'.$record->variant_id),
                TextColumn::make('location.name')->label('Location'),
                TextColumn::make('type')->badge(),
                TextColumn::make('quantity')->color(fn (InventoryMovement $record): string => $record->quantity > 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after')->label('Balance'),
                TextColumn::make('reason')->limit(40)->placeholder('—'),
                TextColumn::make('reference_type')->label('Reference')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->options(collect(MovementType::cases())->mapWithKeys(
                    fn (MovementType $type): array => [$type->value => str_replace('_', ' ', ucfirst($type->value))],
                )->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMovementsPage::route('/')];
    }
}
