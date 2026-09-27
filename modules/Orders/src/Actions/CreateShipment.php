<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Contracts\Outbox;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Models\Shipment;
use Modules\Orders\Models\ShipmentItem;
use Modules\Orders\States\Confirmed;
use Modules\Orders\States\Fulfilled;
use Modules\Orders\States\PartiallyFulfilled;
use Modules\Orders\States\Processing;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Hand some or all remaining items to a courier. Stock was already committed when the order
 * was confirmed (ADR-0010), so shipping moves no stock; it only records what left and how.
 */
final class CreateShipment implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly OrderTransitions $transitions,
        private readonly Outbox $outbox,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, int>|null  $quantities  order item ULID => quantity; null ships everything remaining
     */
    public function handle(string $orderUlid, ?array $quantities, ?string $courier, ?string $trackingNumber, ?Authorizable $actor = null): Shipment
    {
        $this->authorizePermission($actor, 'orders.manage');

        return $this->db->connection()->transaction(function () use ($orderUlid, $quantities, $courier, $trackingNumber, $actor): Shipment {
            $order = OrderLock::take($orderUlid);

            if (! $order->status->equals(Confirmed::class, Processing::class, PartiallyFulfilled::class)) {
                throw ValidationException::withMessages(['status' => __('Only confirmed orders can be shipped.')]);
            }

            $items = $order->items->keyBy('ulid');
            $lines = $quantities ?? $items->map(fn (OrderItem $item): int => $item->unfulfilled())->filter()->all();

            foreach ($lines as $itemUlid => $quantity) {
                $item = $items->get($itemUlid);

                if ($item === null || $quantity < 1 || $quantity > $item->unfulfilled()) {
                    throw ValidationException::withMessages(['items' => __('Ship between 1 and the unshipped quantity of each line.')]);
                }
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['items' => __('Everything on this order has already shipped.')]);
            }

            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'status' => 'in_transit',
                'courier' => $courier,
                'tracking_number' => $trackingNumber,
                'shipped_at' => now(),
            ]);

            foreach ($lines as $itemUlid => $quantity) {
                /** @var OrderItem $item */
                $item = $items->get($itemUlid);
                ShipmentItem::query()->create(['shipment_id' => $shipment->id, 'order_item_id' => $item->id, 'quantity' => $quantity]);
                $item->increment('quantity_fulfilled', $quantity);
            }

            $complete = $order->items->every(fn (OrderItem $item): bool => $item->unfulfilled() === 0);

            if ($complete) {
                $this->transitions->apply($order, Fulfilled::class, null, $actor);
            } elseif (! $order->status instanceof PartiallyFulfilled) {
                $this->transitions->apply($order, PartiallyFulfilled::class, null, $actor);
            }

            $this->outbox->record('order.shipped', ['order_ulid' => $order->ulid, 'shipment_ulid' => $shipment->ulid]);

            return $shipment;
        });
    }
}
