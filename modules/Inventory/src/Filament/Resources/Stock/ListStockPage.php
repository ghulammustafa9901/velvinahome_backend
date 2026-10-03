<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament\Resources\Stock;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Inventory\Actions\ApplyStockCount;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Actions\TransferStock;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Filament\InventoryForms;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\ValueObjects\Money;

/**
 * The inventory workflows as dedicated actions (spec §6.12): receive, transfer, count.
 */
final class ListStockPage extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive')
                ->label('Receive stock')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => InventoryForms::can('inventory.receive'))
                ->modalWidth('4xl')
                ->schema([
                    InventoryForms::location(),
                    TextInput::make('supplier_reference')->label('Supplier invoice / reference')->maxLength(120),
                    InventoryForms::lines(withCost: true),
                    Textarea::make('notes'),
                ])
                ->action(function (array $data): void {
                    $currency = app(StoreSettings::class)->string('store.currency', 'PKR');

                    app(ReceiveStock::class)->handle(StockDocumentData::validateAndCreate([
                        'locationUlid' => $data['location'],
                        'supplierReference' => $data['supplier_reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'lines' => array_values(array_map(fn (array $line): array => [
                            'variantUlid' => $line['variant'],
                            'quantity' => (int) $line['quantity'],
                            'unitCostAmount' => ($line['unit_cost'] ?? '') === '' || $line['unit_cost'] === null ? null : Money::parse((string) $line['unit_cost'], $currency)->amount,
                        ], $data['lines'] ?? [])),
                    ]), InventoryForms::actor());

                    Notification::make()->title('Stock received')->success()->send();
                }),
            Action::make('transfer')
                ->label('Transfer')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn (): bool => InventoryForms::can('inventory.transfer'))
                ->modalWidth('3xl')
                ->schema([
                    InventoryForms::location('from', 'From'),
                    InventoryForms::location('to', 'To')->default(null),
                    InventoryForms::lines(),
                    Textarea::make('notes'),
                ])
                ->action(function (array $data): void {
                    app(TransferStock::class)->handle(StockDocumentData::validateAndCreate([
                        'fromLocationUlid' => $data['from'],
                        'locationUlid' => $data['to'],
                        'notes' => $data['notes'] ?? null,
                        'lines' => $this->lines($data),
                    ]), InventoryForms::actor());

                    Notification::make()->title('Stock transferred')->success()->send();
                }),
            Action::make('count')
                ->label('Stock count')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (): bool => InventoryForms::can('inventory.adjust'))
                ->modalWidth('3xl')
                ->schema([
                    InventoryForms::location(),
                    InventoryForms::lines(quantityLabel: 'Counted'),
                    Textarea::make('notes'),
                ])
                ->action(function (array $data): void {
                    $result = app(ApplyStockCount::class)->handle(StockDocumentData::validateAndCreate([
                        'locationUlid' => $data['location'],
                        'notes' => $data['notes'] ?? null,
                        'lines' => $this->lines($data),
                    ]), InventoryForms::actor());

                    Notification::make()->title('Count applied: '.$result['differences'].' difference(s) posted')->success()->send();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{variantUlid: string, quantity: int}>
     */
    private function lines(array $data): array
    {
        return array_values(array_map(fn (array $line): array => [
            'variantUlid' => (string) $line['variant'],
            'quantity' => (int) $line['quantity'],
        ], $data['lines'] ?? []));
    }
}
