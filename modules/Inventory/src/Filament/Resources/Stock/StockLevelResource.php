<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament\Resources\Stock;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Inventory\Actions\AdjustStock;
use Modules\Inventory\Data\StockAdjustmentData;
use Modules\Inventory\Filament\InventoryForms;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Models\StockLevel;
use UnitEnum;

/**
 * Stock per variant per location. `on_hand` is never edited in place: "edit stock" is an
 * adjustment with a mandatory reason, written to the ledger (spec §6.5).
 */
final class StockLevelResource extends Resource
{
    protected static ?string $model = StockLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'stock level';

    protected static ?string $slug = 'stock';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?int $navigationSort = 1;

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->columns([
                TextColumn::make('variant')->label('Variant')
                    ->state(fn (StockLevel $record): string => ($reference = app(VariantCatalog::class)->findById($record->variant_id)) === null
                        ? '#'.$record->variant_id
                        : $reference->sku.' — '.$reference->title),
                TextColumn::make('location.name')->label('Location'),
                TextColumn::make('on_hand')->label('On hand')->sortable(),
                TextColumn::make('reserved')->sortable(),
                TextColumn::make('available')->state(fn (StockLevel $record): int => $record->available())
                    ->color(fn (StockLevel $record): string => $record->available() <= ($record->low_stock_threshold ?? 0) ? 'danger' : 'success'),
                TextColumn::make('low_stock_threshold')->label('Low at')->placeholder('—'),
                TextColumn::make('updated_at')->since(),
            ])
            ->filters([
                SelectFilter::make('location_id')->label('Location')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('adjust')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (): bool => InventoryForms::can('inventory.adjust'))
                    ->schema([
                        TextInput::make('quantity')->label('Change (+/−)')->numeric()->integer()->required()->notIn([0]),
                        Select::make('type')->options(['adjustment' => 'Adjustment', 'damage' => 'Damage / write-off'])->default('adjustment')->required(),
                        Textarea::make('reason')->required()->minLength(3)->maxLength(500),
                    ])
                    ->action(function (StockLevel $record, array $data): void {
                        $variant = app(VariantCatalog::class)->findById($record->variant_id);
                        abort_if($variant === null, 404);

                        app(AdjustStock::class)->handle(new StockAdjustmentData(
                            $variant->ulid,
                            $record->location->ulid,
                            (int) $data['quantity'],
                            (string) $data['reason'],
                            (string) $data['type'],
                        ), InventoryForms::actor());
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockPage::route('/')];
    }
}
