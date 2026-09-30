<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Contracts\Outbox;
use Modules\Orders\Contracts\OrderBook;
use Modules\Orders\Data\NewOrder;
use Modules\Orders\Data\NewOrderLine;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderAddress;
use Modules\Orders\Models\OrderItem;
use Modules\Settings\Contracts\TenantSettings;

final class EloquentOrderBook implements OrderBook
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly TenantSettings $settings,
        private readonly OrderPresenter $presenter,
    ) {}

    public function place(NewOrder $data): string
    {
        $number = $this->settings->string('orders.number_prefix').(int) DB::scalar("SELECT nextval('order_number_seq')");

        $order = new Order;
        $order->forceFill([
            'ulid' => $data->ulid,
            'number' => $number,
            'customer_id' => $data->customerId,
            'email' => mb_strtolower($data->email),
            'phone' => $data->phone,
            'status' => $data->initialStatus,
            'payment_status' => 'unpaid',
            'payment_method_code' => $data->paymentMethodCode,
            'shipping_method_code' => $data->shippingMethodCode,
            'shipping_method_name' => $data->shippingMethodName,
            'currency' => $data->currency,
            'subtotal' => $data->subtotal,
            'discount' => $data->discount,
            'shipping' => $data->shipping,
            'tax' => $data->tax,
            'total' => $data->total,
            'tax_inclusive' => $data->taxInclusive,
            'coupon_code' => $data->couponCode,
            'promotions' => $data->promotions,
            'customer_note' => $data->customerNote,
            'access_token_hash' => $data->accessTokenHash,
            'idempotency_key' => $data->idempotencyKey,
            'request_hash' => $data->requestHash,
            'ip_address' => $data->ipAddress,
            'placed_at' => now(),
        ])->save();

        foreach ($data->lines as $line) {
            $this->addItem($order, $line);
        }

        OrderAddress::query()->create(['order_id' => $order->id, 'type' => 'shipping'] + $data->shippingAddress->toColumns());

        if ($data->billingAddress !== null) {
            OrderAddress::query()->create(['order_id' => $order->id, 'type' => 'billing'] + $data->billingAddress->toColumns());
        }

        OrderTransitions::record($order, null, $data->initialStatus, null, null);

        $this->outbox->record('order.placed', ['order_ulid' => $order->ulid]);

        return $number;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $row = Order::query()->where('idempotency_key', $key)->first(['ulid', 'number', 'request_hash']);

        return $row === null ? null : ['ulid' => $row->ulid, 'number' => $row->number, 'request_hash' => $row->request_hash];
    }

    public function describe(string $orderUlid): ?array
    {
        $order = Order::query()->where('ulid', $orderUlid)->first();

        return $order === null ? null : $this->presenter->present($order);
    }

    public function deliveredOrderWithProduct(int $customerId, string $productUlid): ?string
    {
        $ulid = Order::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['delivered', 'completed'])
            ->whereHas('items', fn ($query) => $query->where('product_ulid', $productUlid))
            ->orderByDesc('delivered_at')
            ->value('ulid');

        return is_string($ulid) ? $ulid : null;
    }

    private function addItem(Order $order, NewOrderLine $line): void
    {
        $item = new OrderItem;
        $item->forceFill([
            'order_id' => $order->id,
            'variant_ulid' => $line->variantUlid,
            'product_ulid' => $line->productUlid,
            'sku' => $line->sku,
            'title' => $line->title,
            'variant_title' => $line->variantTitle,
            'options' => $line->options,
            'image_url' => $line->imageUrl,
            'quantity' => $line->quantity,
            'currency' => $order->currency,
            'unit_price' => $line->unitPrice,
            'subtotal' => $line->subtotal,
            'discount' => $line->discount,
            'tax' => $line->tax,
            'tax_rate_bps' => $line->taxRateBps,
            'total' => $line->total,
            'reservation_ulid' => $line->reservationUlid,
        ])->save();
    }
}
