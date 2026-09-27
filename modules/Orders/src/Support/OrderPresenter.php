<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Models\OrderStateTransition;
use Modules\Orders\Models\Shipment;
use Modules\Orders\States\OrderState;
use Modules\Payments\Contracts\Payments;

/**
 * The customer's view of an order. Never includes staff notes, reasons written by staff,
 * internal ids or the COD confirmation outcome.
 */
final class OrderPresenter
{
    public function __construct(private readonly Payments $payments) {}

    /**
     * @return array<string, mixed>
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
            'lines' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->ulid,
                'variant_id' => $item->variant_ulid,
                'product_id' => $item->product_ulid,
                'title' => $item->title,
                'variant_title' => $item->variant_title,
                'sku' => $item->sku,
                'options' => (object) $item->options,
                'image_url' => $item->image_url,
                'quantity' => $item->quantity,
                'quantity_shipped' => $item->quantity_fulfilled,
                'quantity_returned' => $item->quantity_returned,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
                'discount' => $item->discount,
                'tax' => $item->tax,
                'total' => $item->total,
            ])->values()->all(),
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
                'paid' => $payment['paid'] ?? null,
                'refunded' => $payment['refunded'] ?? null,
                // Bank/wallet details for a transfer the customer still has to make.
                'instructions' => $order->payment_status === 'unpaid' && $order->payment_method_code === 'manual_transfer' ? ($payment['instructions'] ?? null) : null,
                'can_upload_proof' => $order->payment_method_code === 'manual_transfer'
                    && in_array($order->payment_status, ['unpaid', 'awaiting_verification'], true)
                    && $order->status->cancellable(),
            ],
            'shipments' => $order->shipments->map(fn (Shipment $shipment): array => [
                'id' => $shipment->ulid,
                'status' => $shipment->status,
                'courier' => $shipment->courier,
                'tracking_number' => $shipment->tracking_number,
                'shipped_at' => $shipment->shipped_at->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            ])->values()->all(),
            'timeline' => $order->transitions->map(fn (OrderStateTransition $transition): array => [
                'status' => $transition->to_status,
                'label' => OrderState::labelFor($transition->to_status, $order),
                'at' => $transition->created_at->toIso8601String(),
            ])->values()->all(),
            'customer_note' => $order->customer_note,
        ];
    }

    /**
     * @return array<string, mixed>
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
}
