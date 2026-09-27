<?php

declare(strict_types=1);

namespace Modules\Orders\Filament\Resources\Orders;

use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Orders\Filament\OrderAccess;
use Modules\Orders\Models\Order;
use Modules\Orders\States\OrderState;
use Modules\Support\ValueObjects\Money;
use UnitEnum;

/**
 * Order management (spec §6.12): find orders, then confirm, fulfil, cancel and refund them
 * from the order page. Every button runs an Orders Action, which checks the permission.
 */
final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public const STATUSES = [
        'pending_payment' => 'Awaiting payment',
        'awaiting_confirmation' => 'Awaiting confirmation',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'partially_fulfilled' => 'Partially shipped',
        'fulfilled' => 'Shipped',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid',
        'awaiting_verification' => 'Receipt to verify',
        'paid' => 'Paid',
        'partially_refunded' => 'Partially refunded',
        'refunded' => 'Refunded',
        'voided' => 'Voided',
    ];

    public static function canViewAny(): bool
    {
        return OrderAccess::can('orders.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function money(mixed $state): string
    {
        return $state instanceof Money ? $state->format() : '—';
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'pending_payment', 'awaiting_confirmation' => 'warning',
            'confirmed', 'processing', 'partially_fulfilled' => 'info',
            'fulfilled', 'delivered', 'completed' => 'success',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('bold'),
                TextColumn::make('placed_at')->dateTime()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OrderState $state): string => $state->label())
                    ->color(fn (OrderState $state): string => self::statusColor($state::getMorphClass())),
                TextColumn::make('payment_status')->label('Payment')->badge()
                    ->formatStateUsing(fn (string $state): string => self::PAYMENT_STATUSES[$state] ?? $state),
                TextColumn::make('payment_method_code')->label('Method')->formatStateUsing(fn (string $state): string => $state === 'cod' ? 'COD' : 'Transfer'),
                TextColumn::make('total')->formatStateUsing(fn (mixed $state): string => self::money($state))->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES)->multiple(),
                SelectFilter::make('payment_status')->label('Payment')->options(self::PAYMENT_STATUSES),
                SelectFilter::make('payment_method_code')->label('Method')->options(['cod' => 'Cash on Delivery', 'manual_transfer' => 'Bank / wallet transfer']),
            ])
            ->defaultSort('placed_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Section::make('Order')->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (OrderState $state): string => $state->label())
                        ->color(fn (OrderState $state): string => self::statusColor($state::getMorphClass())),
                    TextEntry::make('payment_status')->label('Payment')->badge()
                        ->formatStateUsing(fn (string $state): string => self::PAYMENT_STATUSES[$state] ?? $state),
                    TextEntry::make('payment_method_code')->label('Payment method')
                        ->formatStateUsing(fn (string $state): string => $state === 'cod' ? 'Cash on Delivery' : 'Bank / wallet transfer'),
                    TextEntry::make('placed_at')->dateTime(),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('phone')->copyable(),
                    TextEntry::make('cod_confirmation')->label('COD call')->placeholder('Not called yet')
                        ->formatStateUsing(fn (?string $state, Order $record): string => ucfirst((string) $state).' ('.$record->cod_attempts.' '.str('attempt')->plural($record->cod_attempts).')')
                        ->visible(fn (Order $record): bool => $record->isCod()),
                    TextEntry::make('shipping_method_name')->label('Shipping')->placeholder('—'),
                    TextEntry::make('customer_note')->label('Customer note')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('cancel_reason')->label('Cancellation reason')->visible(fn (Order $record): bool => $record->cancel_reason !== null)->columnSpanFull(),
                ]),
                Section::make('Totals')->schema([
                    TextEntry::make('subtotal')->formatStateUsing(fn (mixed $state): string => self::money($state)),
                    TextEntry::make('discount')->formatStateUsing(fn (mixed $state): string => self::money($state))
                        ->helperText(fn (Order $record): ?string => $record->coupon_code),
                    TextEntry::make('shipping')->formatStateUsing(fn (mixed $state): string => self::money($state)),
                    TextEntry::make('tax')->formatStateUsing(fn (mixed $state): string => self::money($state))
                        ->helperText(fn (Order $record): string => $record->tax_inclusive ? 'Included in prices' : 'Added to prices'),
                    TextEntry::make('total')->weight('bold')->formatStateUsing(fn (mixed $state): string => self::money($state)),
                ]),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('title')->columnSpan(2)->helperText(fn ($record): ?string => $record?->variant_title),
                    TextEntry::make('sku'),
                    TextEntry::make('quantity'),
                    TextEntry::make('quantity_fulfilled')->label('Shipped / returned')
                        ->formatStateUsing(fn (int $state, $record): string => $state.' / '.$record?->quantity_returned),
                    TextEntry::make('total')->formatStateUsing(fn (mixed $state): string => self::money($state)),
                ]),
            ]),
            Grid::make(2)->schema([
                Section::make('Shipping address')->schema([
                    TextEntry::make('shippingAddress.first_name')->label('Name')
                        ->formatStateUsing(fn (Order $record): string => $record->shippingAddress === null ? '—' : trim($record->shippingAddress->first_name.' '.$record->shippingAddress->last_name)),
                    TextEntry::make('shippingAddress.line1')->label('Address')
                        ->formatStateUsing(fn (Order $record): string => implode(', ', array_filter([
                            $record->shippingAddress?->line1, $record->shippingAddress?->line2, $record->shippingAddress?->city,
                            $record->shippingAddress?->province, $record->shippingAddress?->postal_code, $record->shippingAddress?->country_code,
                        ]))),
                    TextEntry::make('shippingAddress.phone')->label('Phone')->copyable(),
                ]),
                Section::make('Shipments')->schema([
                    RepeatableEntry::make('shipments')->hiddenLabel()->columns(3)->placeholder('Nothing shipped yet')->schema([
                        TextEntry::make('courier')->placeholder('—')->helperText(fn ($record): ?string => $record?->tracking_number),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('shipped_at')->dateTime(),
                    ]),
                ]),
            ]),
            Grid::make(2)->schema([
                Section::make('Timeline')->schema([
                    RepeatableEntry::make('transitions')->hiddenLabel()->columns(3)->schema([
                        TextEntry::make('to_status')->label('Status')->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                        TextEntry::make('reason')->placeholder('—'),
                        TextEntry::make('created_at')->dateTime()->helperText(fn ($record): ?string => $record?->actor_type),
                    ]),
                ]),
                Section::make('Staff notes')->schema([
                    RepeatableEntry::make('notes')->hiddenLabel()->placeholder('No notes')->schema([
                        TextEntry::make('body')->hiddenLabel()->helperText(fn ($record): ?string => $record?->created_at?->toDayDateTimeString()),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrdersPage::route('/'),
            'view' => ViewOrderPage::route('/{record}'),
        ];
    }
}
