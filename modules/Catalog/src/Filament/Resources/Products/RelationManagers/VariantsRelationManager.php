<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Catalog\Actions\UpdateVariant;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\ProductVariant;
use Modules\Support\ValueObjects\Money;

/**
 * Variants of the product being edited: price, compare-at, barcode, weight, backorders.
 * Stock is managed on the Inventory screens, never here.
 */
final class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('title')->placeholder('Default'),
                TextColumn::make('price')->state(fn (ProductVariant $record): string => $record->price->format()),
                TextColumn::make('compare_at_price')->label('Compare at')
                    ->state(fn (ProductVariant $record): string => $record->compare_at_price?->format() ?? '—'),
                TextColumn::make('cost')->label('Avg. cost')
                    ->state(fn (ProductVariant $record): string => $record->cost?->format() ?? '—'),
                IconColumn::make('allow_backorder')->boolean()->label('Backorder'),
                IconColumn::make('is_default')->boolean()->label('Default'),
            ])
            ->recordActions([
                Action::make('editVariant')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => StaffAccess::can('products.manage'))
                    ->fillForm(fn (ProductVariant $record): array => [
                        'price' => $record->price->toDecimalString(),
                        'compare_at_price' => $record->compare_at_price?->toDecimalString(),
                        'barcode' => $record->barcode,
                        'weight_grams' => $record->weight_grams,
                        'allow_backorder' => $record->allow_backorder,
                    ])
                    ->schema([
                        TextInput::make('price')->required()->regex('/^\d[\d,]*(\.\d{1,2})?$/')->prefix('Rs'),
                        TextInput::make('compare_at_price')->regex('/^\d[\d,]*(\.\d{1,2})?$/')->prefix('Rs'),
                        TextInput::make('barcode')->maxLength(64),
                        TextInput::make('weight_grams')->numeric()->minValue(0),
                        Toggle::make('allow_backorder'),
                    ])
                    ->action(function (ProductVariant $record, array $data): void {
                        $currency = $record->price->currency;

                        app(UpdateVariant::class)->handle(
                            $record,
                            Money::parse((string) $data['price'], $currency)->amount,
                            ($data['compare_at_price'] ?? '') === '' || $data['compare_at_price'] === null ? null : Money::parse((string) $data['compare_at_price'], $currency)->amount,
                            $data['barcode'] ?? null,
                            ($data['weight_grams'] ?? '') === '' || $data['weight_grams'] === null ? null : (int) $data['weight_grams'],
                            (bool) ($data['allow_backorder'] ?? false),
                            StaffAccess::actor(),
                        );
                    }),
            ]);
    }
}
