<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Models\OrderStateTransition;
use Modules\Orders\Models\Shipment;
use Modules\Orders\States\OrderState;
use Modules\Payments\Contracts\Payments;
use Modules\Support\ValueObjects\Money;
use Modules\Support\ValueObjects\StringMap;

/**
 * The customer's view of an order. Never includes staff notes, reasons written by staff,
 * internal ids or the COD confirmation outcome.
 */
final class OrderPresenter
{
    public function __construct(private readonly Payments $payments) {}

    /**
     * @return array{id: string, number: string, status: string, status_label: string, placed_at: string, email: string, phone: string, currency: string, lines: list<array{id: string, variant_id: string, product_id: string, title: string, variant_title: string|null, sku: string, options: StringMap, image_url: string|null, quantity: int, quantity_shipped: int, quantity_returned: int, unit_price: Money, subtotal: Money, discount: Money, tax: Money, total: Money}>, totals: array{subtotal: Money, discount: Money, shipping: Money, tax: Money, tax_inclusive: bool, total: Money}, coupon_code: string|null, shipping_address: array{first_name: string, last_name: string, phone: string, line1: string, line2: string|null, city: string, province: string|null, postal_code: string|null, country_code: string}|null, shipping_method: array{code: string, name: string|null}|null, payment: array{method: string, status: string, paid: Money|null, refunded: Money|null, instructions: string|null, can_upload_proof: bool}, shipments: list<array{id: string, status: string, courier: string|null, tracking_number: string|null, shipped_at: string, delivered_at: string|null}>, timeline: list<array{status: string, label: string, at: string}>, customer_note: string|null}
     *
     * @scramble-return array{id: string, number: string, status: string, status_label: string, placed_at: string, email: string, phone: string, currency: string, lines: list<array{id: string, variant_id: string, product_id: string, title: string, variant_title: string|null, sku: string, options: \Modules\Support\ValueObjects\StringMap, image_url: string|null, quantity: int, quantity_shipped: int, quantity_returned: int, unit_price: \Modules\Support\ValueObjects\Money, subtotal: \Modules\Support\ValueObjects\Money, discount: \Modules\Support\ValueObjects\Money, tax: \Modules\Support\ValueObjects\Money, total: \Modules\Support\ValueObjects\Money}>, totals: array{subtotal: \Modules\Support\ValueObjects\Money, discount: \Modules\Support\ValueObjects\Money, shipping: \Modules\Support\ValueObjects\Money, tax: \Modules\Support\ValueObjects\Money, tax_inclusive: bool, total: \Modules\Support\ValueObjects\Money}, coupon_code: string|null, shipping_address: array{first_name: string, last_name: string, phone: string, line1: string, line2: string|null, city: string, province: string|null, postal_code: string|null, country_code: string}|null, shipping_method: array{code: string, name: string|null}|null, payment: array{method: string, status: string, paid: \Modules\Support\ValueObjects\Money|null, refunded: \Modules\Support\ValueObjects\Money|null, instructions: string|null, can_upload_proof: bool}, shipments: list<array{id: string, status: string, courier: string|null, tracking_number: string|null, shipped_at: string, delivered_at: string|null}>, timeline: list<array{status: string, label: string, at: string}>, customer_note: string|null}
     */
    public function present(Order $order): array
    {
        $order->loadMissing(['items', 'shippingAddress', 'shipments', 'transitions']);
        $payment = $this->payments->summary($order->ulid);

        return [
            'id' => $order->ulid,
            'number' => $order->number,
            'status' => $order->status::getMorphClass(),
            'status_label' => $order->status->label(),
            'placed_at' => $order->placed_at->toIso8601String(),
            'email' => $order->email,
            'phone' => $order->phone,
            'currency' => $order->currency,
            'lines' => array_values($order->items->map(fn (OrderItem $item): array => [
                'id' => $item->ulid,
                'variant_id' => $item->variant_ulid,
                'product_id' => $item->product_ulid,
                'title' => $item->title,
                'variant_title' => $item->variant_title,
                'sku' => $item->sku,
                'options' => StringMap::from($item->options),
                'image_url' => $item->image_url,
                'quantity' => $item->quantity,
                'quantity_shipped' => $item->quantity_fulfilled,
                'quantity_returned' => $item->quantity_returned,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
                'discount' => $item->discount,
                'tax' => $item->tax,
                'total' => $item->total,
            ])->all()),
            'totals' => [
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'shipping' => $order->shipping,
                'tax' => $order->tax,
                'tax_inclusive' => $order->tax_inclusive,
                'total' => $order->total,
            ],
            'coupon_code' => $order->coupon_code,
            'shipping_address' => $order->shippingAddress?->toApi(),
            'shipping_method' => $order->shipping_method_code === null ? null : [
                'code' => $order->shipping_method_code,
                'name' => $order->shipping_method_name,
            ],
            'payment' => [
                'method' => $order->payment_method_code,
                'status' => $order->payment_status,
                'paid' => self::money($payment, 'paid'),
                'refunded' => self::money($payment, 'refunded'),
                // Bank/wallet details for a transfer the customer still has to make.
                'instructions' => $order->payment_status === 'unpaid' && $order->payment_method_code === 'manual_transfer' ? ($payment['instructions'] ?? null) : null,
                'can_upload_proof' => (bool) ($order->payment_method_code === 'manual_transfer'
                    && in_array($order->payment_status, ['unpaid', 'awaiting_verification'], true)
                    && $order->status->cancellable()),
            ],
            'shipments' => array_values($order->shipments->map(fn (Shipment $shipment): array => [
                'id' => $shipment->ulid,
                'status' => $shipment->status,
                'courier' => $shipment->courier,
                'tracking_number' => $shipment->tracking_number,
                'shipped_at' => $shipment->shipped_at->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            ])->all()),
            'timeline' => array_values($order->transitions->map(fn (OrderStateTransition $transition): array => [
                'status' => $transition->to_status,
                'label' => OrderState::labelFor($transition->to_status, $order),
                'at' => $transition->created_at->toIso8601String(),
            ])->all()),
            'customer_note' => $order->customer_note,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payment
     */
    private static function money(?array $payment, string $key): ?Money
    {
        $value = $payment[$key] ?? null;

        return $value instanceof Money ? $value : null;
    }

    /**
     * @return array{id: string, number: string, status: string, status_label: string, payment_status: string, placed_at: string, item_count: int, total: Money}
     *
     * @scramble-return array{id: string, number: string, status: string, status_label: string, payment_status: string, placed_at: string, item_count: int, total: \Modules\Support\ValueObjects\Money}
     */
    public function summary(Order $order): array
    {
        return [
            'id' => $order->ulid,
            'number' => $order->number,
            'status' => $order->status::getMorphClass(),
            'status_label' => $order->status->label(),
            'payment_status' => $order->payment_status,
            'placed_at' => $order->placed_at->toIso8601String(),
            'item_count' => (int) $order->getAttribute('item_count'),
            'total' => $order->total,
        ];
    }

    /**
     * @param  iterable<Order>  $orders
     * @return list<array{id: string, number: string, status: string, status_label: string, payment_status: string, placed_at: string, item_count: int, total: Money}>
     *
     * @scramble-return list<array{id: string, number: string, status: string, status_label: string, payment_status: string, placed_at: string, item_count: int, total: \Modules\Support\ValueObjects\Money}>
     */
    public function summaries(iterable $orders): array
    {
        $summaries = [];

        foreach ($orders as $order) {
            $summaries[] = $this->summary($order);
        }

        return $summaries;
    }
}
