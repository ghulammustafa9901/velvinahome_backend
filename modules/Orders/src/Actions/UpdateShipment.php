<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Models\Shipment;
use Modules\Orders\States\Delivered;
use Modules\Orders\States\Fulfilled;
use Modules\Orders\States\Returned;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Orders\Support\PaymentStatusMirror;
use Modules\Payments\Contracts\Payments;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * A shipment's outcome: delivered, or returned to origin (RTO — the parcel came back
 * undelivered, common with COD). Delivery of the last parcel delivers the order and, for COD,
 * records the cash as collected. An RTO puts the parcel's stock back with a reason.
 */
final class UpdateShipment implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly StockReservations $stock,
        private readonly Payments $payments,
        private readonly OrderTransitions $transitions,
        private readonly PaymentStatusMirror $paymentStatus,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  'delivered'|'returned'  $outcome
     */
    public function handle(string $shipmentUlid, string $outcome, ?string $reason = null, ?Authorizable $actor = null): Shipment
    {
        $this->authorizePermission($actor, 'orders.manage');

        return $this->db->connection()->transaction(function () use ($shipmentUlid, $outcome, $reason, $actor): Shipment {
            $orderUlid = Order::query()->whereKey(Shipment::query()->where('ulid', $shipmentUlid)->value('order_id'))->value('ulid');
            abort_if(! is_string($orderUlid), 404);

            $order = OrderLock::take($orderUlid);
            $shipment = Shipment::query()->where('ulid', $shipmentUlid)->lockForUpdate()->firstOrFail();

            if ($shipment->status !== 'in_transit') {
                throw ValidationException::withMessages(['shipment' => __('This shipment has already been closed.')]);
            }

            if ($outcome === 'delivered') {
                $shipment->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
                $this->afterDelivery($order, $actor);
            } else {
                $shipment->forceFill(['status' => 'returned', 'returned_at' => now()])->save();
                $this->afterReturnToOrigin($order, $shipment, $reason ?? 'Returned to origin', $actor);
            }

            return $shipment;
        });
    }

    private function afterDelivery(Order $order, ?Authorizable $actor): void
    {
        if (! $order->status instanceof Fulfilled || $order->shipments()->where('status', 'in_transit')->exists()) {
            return;
        }

        $this->transitions->apply($order, Delivered::class, null, $actor);

        if ($order->isCod()) {
            $this->payments->recordCodCollected($order->ulid, OrderTransitions::actorUlid($actor));
            $this->paymentStatus->sync($order);
        }
    }

    private function afterReturnToOrigin(Order $order, Shipment $shipment, string $reason, ?Authorizable $actor): void
    {
        foreach ($shipment->items()->with('orderItem')->get() as $line) {
            $item = $line->orderItem;

            if ($item->reservation_ulid !== null) {
                $this->stock->restock($item->reservation_ulid, $line->quantity, 'RTO order '.$order->number.': '.$reason, $order->ulid);
            }

            $item->increment('quantity_returned', $line->quantity);
        }

        $order->unsetRelation('items');
        $everythingBack = $order->items->every(fn (OrderItem $item): bool => $item->quantity_returned === $item->quantity);

        if ($everythingBack && $order->status instanceof Fulfilled) {
            $this->transitions->apply($order, Returned::class, $reason, $actor);

            // Nothing was collected on an undelivered COD parcel.
            $this->payments->cancelOpen($order->ulid);
            $this->paymentStatus->sync($order);
        }
    }
}
