<?php

declare(strict_types=1);

namespace Modules\Imports\Filament\Resources\ImportedOrders;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\HtmlString;
use Modules\Imports\Models\ImportedOrder;
use Modules\Support\ValueObjects\Money;
use UnitEnum;

/**
 * Orders from the previous store, for reference (customer questions, returns of old orders).
 */
final class ImportedOrderResource extends Resource
{
    protected static ?string $model = ImportedOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Order history (imported)';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable && $user->can('orders.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('placed_at')->dateTime()->sortable(),
                TextColumn::make('customer_name')->placeholder('—')->description(fn (ImportedOrder $record): ?string => $record->email)
                    ->searchable(['customer_name', 'email', 'phone']),
                TextColumn::make('status')->badge()->color('gray'),
                TextColumn::make('total')->formatStateUsing(fn (Money $state): string => $state->format()),
            ])
            ->recordActions([
                Action::make('view')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (ImportedOrder $record): string => 'Order '.$record->number)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (ImportedOrder $record): HtmlString => self::details($record)),
            ])
            ->defaultSort('placed_at', 'desc');
    }

    private static function details(ImportedOrder $order): HtmlString
    {
        $rows = '';
        foreach ($order->lines as $line) {
            $rows .= '<tr><td class="py-1 pr-4">'.e($line['title']).($line['sku'] !== null ? ' <span class="text-gray-500">'.e($line['sku']).'</span>' : '').'</td>'
                .'<td class="py-1 pr-4 text-right">'.$line['quantity'].'</td>'
                .'<td class="py-1 text-right">'.e(Money::of($line['total'], $order->currency)->format()).'</td></tr>';
        }

        $address = $order->shipping_address === null ? '—' : e(implode(', ', array_filter($order->shipping_address)));

        return new HtmlString('<div class="space-y-3 text-sm">'
            .'<p>'.e((string) $order->customer_name).' · '.e((string) $order->email).' · '.e((string) $order->phone).'</p>'
            .'<p>'.$address.'</p>'
            .'<table class="w-full">'.$rows.'</table>'
            .'<p>Shipping '.e($order->shipping->format()).' · Discount '.e($order->discount->format()).' · Tax '.e($order->tax->format()).' · <strong>Total '.e($order->total->format()).'</strong></p>'
            .'<p class="text-gray-500">Paid with '.e((string) $order->payment_method).'. Imported from '.e($order->source).'.</p>'
            .'</div>');
    }

    public static function getPages(): array
    {
        return ['index' => ListImportedOrdersPage::route('/')];
    }
}
