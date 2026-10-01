<?php

declare(strict_types=1);

namespace Modules\Orders\Filament\Resources\Orders;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Modules\Orders\Actions\AddOrderNote;
use Modules\Orders\Actions\AdvanceOrder;
use Modules\Orders\Actions\CancelOrder;
use Modules\Orders\Actions\ConfirmCodOrder;
use Modules\Orders\Actions\CreateShipment;
use Modules\Orders\Actions\RecordReturn;
use Modules\Orders\Actions\RefundOrder;
use Modules\Orders\Actions\UpdateShipment;
use Modules\Orders\Filament\OrderAccess;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\States\AwaitingConfirmation;
use Modules\Orders\States\Completed;
use Modules\Orders\States\Confirmed;
use Modules\Orders\States\Delivered;
use Modules\Orders\States\PartiallyFulfilled;
use Modules\Orders\States\PendingPayment;
use Modules\Orders\States\Processing;
use Modules\Payments\Contracts\Payments;
use Modules\Shipping\Contracts\Couriers;
use Modules\Support\ValueObjects\Money;

/**
 * One order and every staff workflow on it. The buttons shown depend on the order's state
 * and the staff member's permissions; the Actions enforce both again.
 */
final class ViewOrderPage extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    private function order(): Order
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return $order;
    }

    private function done(string $message): void
    {
        $this->getRecord()->refresh();
        Notification::make()->title($message)->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmCod(),
            $this->reviewTransfer(true),
            $this->reviewTransfer(false),
            Action::make('process')->label('Start processing')->icon(Heroicon::OutlinedArchiveBox)
                ->visible(fn (): bool => OrderAccess::can('orders.manage') && $this->order()->status instanceof Confirmed)
                ->action(function (): void {
                    app(AdvanceOrder::class)->handle($this->order()->ulid, 'processing', OrderAccess::actor());
                    $this->done('Order is being processed');
                }),
            $this->ship(),
            $this->shipmentOutcome(),
            Action::make('complete')->label('Complete')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (): bool => OrderAccess::can('orders.manage') && $this->order()->status instanceof Delivered)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(AdvanceOrder::class)->handle($this->order()->ulid, 'completed', OrderAccess::actor());
                    $this->done('Order completed');
                }),
            ActionGroup::make([
                $this->recordReturn(),
                $this->refund(),
                $this->cancel(),
                $this->viewReceipt(),
                Action::make('note')->label('Add staff note')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->visible(fn (): bool => OrderAccess::can('orders.manage'))
                    ->schema([Textarea::make('body')->required()->maxLength(5000)])
                    ->action(function (array $data): void {
                        app(AddOrderNote::class)->handle($this->order()->ulid, (string) $data['body'], OrderAccess::actor());
                        $this->done('Note added');
                    }),
            ])->label('More')->button()->color('gray'),
        ];
    }

    private function confirmCod(): Action
    {
        return Action::make('confirmCod')->label('COD confirmation call')->icon(Heroicon::OutlinedPhone)->color('primary')
            ->visible(fn (): bool => OrderAccess::can('orders.confirm_cod') && $this->order()->status instanceof AwaitingConfirmation)
            ->schema([
                Select::make('outcome')->required()->options([
                    'confirmed' => 'Customer confirmed',
                    'unreachable' => 'Could not reach customer',
                    'fake' => 'Fake order — cancel it',
                ]),
                Textarea::make('note')->maxLength(1000),
            ])
            ->action(function (array $data): void {
                app(ConfirmCodOrder::class)->handle($this->order()->ulid, (string) $data['outcome'], $data['note'] ?? null, OrderAccess::actor());
                $this->done('Confirmation recorded');
            });
    }

    private function reviewTransfer(bool $accept): Action
    {
        return Action::make($accept ? 'verifyTransfer' : 'rejectTransfer')
            ->label($accept ? 'Verify transfer' : 'Reject transfer')
            ->icon($accept ? Heroicon::OutlinedBanknotes : Heroicon::OutlinedXCircle)
            ->color($accept ? 'success' : 'danger')
            ->visible(fn (): bool => OrderAccess::can('orders.manage')
                && $this->order()->status instanceof PendingPayment
                && in_array($this->order()->payment_status, ['unpaid', 'awaiting_verification'], true))
            ->schema($accept
                ? [TextInput::make('reference')->label('Bank / wallet reference')->maxLength(120)->helperText('As shown on your statement.')]
                : [Textarea::make('reason')->required()->maxLength(500)->helperText('The order is cancelled and its stock released.')])
            ->requiresConfirmation(! $accept)
            ->action(function (array $data) use ($accept): void {
                app(Payments::class)->reviewTransfer($this->order()->ulid, $accept, $data['reference'] ?? null, $data['reason'] ?? null, OrderAccess::actor());
                $this->done($accept ? 'Payment verified; order confirmed' : 'Transfer rejected; order cancelled');
            });
    }

    private function viewReceipt(): Action
    {
        return Action::make('receipt')->label('View transfer receipt')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->visible(fn (): bool => OrderAccess::can('orders.view') && $this->order()->payment_method_code === 'manual_transfer')
            ->url(fn (): ?string => app(Payments::class)->proofUrl($this->order()->ulid), shouldOpenInNewTab: true)
            ->disabled(fn (): bool => app(Payments::class)->proofUrl($this->order()->ulid) === null);
    }

    private function ship(): Action
    {
        return Action::make('ship')->label('Ship')->icon(Heroicon::OutlinedTruck)->color('primary')
            ->visible(fn (): bool => OrderAccess::can('orders.manage')
                && $this->order()->status->equals(Confirmed::class, Processing::class, PartiallyFulfilled::class))
            ->fillForm(fn (): array => ['lines' => $this->order()->items->mapWithKeys(fn (OrderItem $item): array => [$item->ulid => $item->unfulfilled()])->all()])
            ->schema(fn (): array => [
                TextInput::make('courier')->maxLength(64)->datalist(fn (): array => array_values(app(Couriers::class)->options())),
                TextInput::make('tracking_number')->maxLength(100),
                ...$this->order()->items->filter(fn (OrderItem $item): bool => $item->unfulfilled() > 0)->map(
                    fn (OrderItem $item) => TextInput::make('lines.'.$item->ulid)
                        ->label($item->title.($item->variant_title ? ' — '.$item->variant_title : '').' ('.$item->sku.')')
                        ->numeric()->integer()->minValue(0)->maxValue($item->unfulfilled())->required(),
                )->values()->all(),
            ])
            ->action(function (array $data): void {
                $lines = array_filter(array_map('intval', (array) ($data['lines'] ?? [])), fn (int $quantity): bool => $quantity > 0);
                app(CreateShipment::class)->handle($this->order()->ulid, $lines, $data['courier'] ?? null, $data['tracking_number'] ?? null, OrderAccess::actor());
                $this->done('Shipment created');
            });
    }

    private function shipmentOutcome(): Action
    {
        return Action::make('shipmentOutcome')->label('Update shipment')->icon(Heroicon::OutlinedMapPin)
            ->visible(fn (): bool => OrderAccess::can('orders.manage') && $this->order()->shipments->contains('status', 'in_transit'))
            ->schema(fn (): array => [
                Select::make('shipment')->required()->options(
                    $this->order()->shipments->where('status', 'in_transit')
                        ->mapWithKeys(fn ($shipment): array => [$shipment->ulid => trim(($shipment->courier ?? 'Shipment').' '.($shipment->tracking_number ?? '').' — '.$shipment->shipped_at->toFormattedDateString())])
                        ->all(),
                ),
                Select::make('outcome')->required()->options(['delivered' => 'Delivered', 'returned' => 'Returned to origin (RTO) — restocks the parcel']),
                Textarea::make('reason')->label('RTO reason')->maxLength(500),
            ])
            ->action(function (array $data): void {
                /** @var 'delivered'|'returned' $outcome */
                $outcome = $data['outcome'] === 'returned' ? 'returned' : 'delivered';
                app(UpdateShipment::class)->handle((string) $data['shipment'], $outcome, $data['reason'] ?? null, OrderAccess::actor());
                $this->done($outcome === 'delivered' ? 'Marked delivered' : 'Marked returned to origin');
            });
    }

    private function recordReturn(): Action
    {
        return Action::make('return')->label('Record customer return')->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (): bool => OrderAccess::can('orders.manage') && $this->order()->status->equals(Delivered::class, Completed::class))
            ->schema(fn (): array => [
                Select::make('item')->required()->options(
                    $this->order()->items->filter(fn (OrderItem $item): bool => $item->quantity_fulfilled > $item->quantity_returned)
                        ->mapWithKeys(fn (OrderItem $item): array => [$item->ulid => $item->title.' ('.$item->sku.') — up to '.($item->quantity_fulfilled - $item->quantity_returned)])
                        ->all(),
                ),
                TextInput::make('quantity')->numeric()->integer()->minValue(1)->required()->default(1),
                Textarea::make('reason')->required()->maxLength(500),
                Toggle::make('restock')->label('Put back on sale')->default(true)->helperText('Turn off for damaged goods.'),
            ])
            ->action(function (array $data): void {
                app(RecordReturn::class)->handle($this->order()->ulid, [(string) $data['item'] => (int) $data['quantity']], (string) $data['reason'], (bool) $data['restock'], OrderAccess::actor());
                $this->done('Return recorded');
            });
    }

    private function refund(): Action
    {
        return Action::make('refund')->label('Record refund')->icon(Heroicon::OutlinedReceiptRefund)->color('warning')
            ->visible(fn (): bool => OrderAccess::can('orders.refund') && in_array($this->order()->payment_status, ['paid', 'partially_refunded'], true))
            ->schema([
                // One key per opened form: a double-submitted form is still one refund.
                Hidden::make('key')->default(fn (): string => 'refund-'.Str::ulid()),
                TextInput::make('amount')->required()->prefix('Rs')->regex('/^\d[\d,]*(\.\d{1,2})?$/')
                    ->helperText('Record money you have already sent back to the customer.'),
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $amount = Money::parse(str_replace(',', '', (string) $data['amount']), $this->order()->currency);
                app(RefundOrder::class)->handle($this->order()->ulid, $amount, (string) $data['reason'], (string) $data['key'], OrderAccess::actor());
                $this->done('Refund recorded');
            });
    }

    private function cancel(): Action
    {
        return Action::make('cancel')->label('Cancel order')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn (): bool => OrderAccess::can('orders.manage') && $this->order()->status->cancellable())
            ->schema([Textarea::make('reason')->required()->maxLength(500)->helperText('Stock goes back on sale. Money already received must be refunded separately.')])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                app(CancelOrder::class)->handle($this->order()->ulid, (string) $data['reason'], OrderAccess::actor());
                $this->done('Order cancelled');
            });
    }
}
