<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament\Resources\Locations;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Filament\InventoryForms;
use Modules\Inventory\Models\Location;
use UnitEnum;

final class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return InventoryForms::can('inventory.view');
    }

    public static function canCreate(): bool
    {
        return InventoryForms::can('inventory.adjust');
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
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('code')->required()->regex('/^[A-Za-z0-9_-]{1,32}$/'),
            Select::make('type')->options(['warehouse' => 'Warehouse', 'shop' => 'Shop', '3pl' => '3PL'])->default('warehouse')->required(),
            TextInput::make('priority')->numeric()->integer()->default(0)
                ->helperText('Higher priority locations are used first when reserving stock.'),
            Toggle::make('is_default')->label('Default location'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('type')->badge(),
                TextColumn::make('priority')->sortable(),
                IconColumn::make('is_default')->boolean()->label('Default'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->defaultSort('priority', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListLocationsPage::route('/')];
    }
}
