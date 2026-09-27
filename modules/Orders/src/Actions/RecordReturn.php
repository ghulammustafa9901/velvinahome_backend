<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Models\OrderReturn;
use Modules\Orders\Models\OrderReturnItem;
use Modules\Orders\States\Completed;
use Modules\Orders\States\Delivered;
use Modules\Orders\States\Returned;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Goods a customer sent back after delivery. Resaleable goods go back on sale with a reason;
 * damaged goods are recorded without restocking. Refunds are a separate, explicit step.
 */
final class RecordReturn implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly StockReservations $stock,
        private readonly OrderTransitions $transitions,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, int>  $quantities  order item ULID => quantity returned
     */
    public function handle(string $orderUlid, array $quantities, string $reason, bool $restock, ?Authorizable $actor = null): OrderReturn
    {
        $this->authorizePermission($actor, 'orders.manage');

        return $this->db->connection()->transaction(function () use ($orderUlid, $quantities, $reason, $restock, $actor): OrderReturn {
            $order = OrderLock::take($orderUlid);

            if (! $order->status->equals(Delivered::class, Completed::class)) {
                throw ValidationException::withMessages(['status' => __('Returns are recorded for delivered orders.')]);
            }

            $items = $order->items->keyBy('ulid');
            $quantities = array_filter($quantities, fn (int $quantity): bool => $quantity > 0);

            foreach ($quantities as $itemUlid => $quantity) {
                $item = $items->get($itemUlid);

                if ($item === null || $quantity > $item->quantity_fulfilled - $item->quantity_returned) {
                    throw ValidationException::withMessages(['items' => __('Return at most what was delivered and not yet returned.')]);
                }
            }

            if ($quantities === []) {
                throw ValidationException::withMessages(['items' => __('Choose at least one item to return.')]);
            }

            $return = OrderReturn::query()->create([
                'order_id' => $order->id,
                'reason' => $reason,
                'restocked' => $restock,
                'actor_ulid' => OrderTransitions::actorUlid($actor),
            ]);

            foreach ($quantities as $itemUlid => $quantity) {
                /** @var OrderItem $item */
                $item = $items->get($itemUlid);
                OrderReturnItem::query()->create(['order_return_id' => $return->id, 'order_item_id' => $item->id, 'quantity' => $quantity]);
                $item->increment('quantity_returned', $quantity);

                if ($restock && $item->reservation_ulid !== null) {
                    $this->stock->restock($item->reservation_ulid, $quantity, 'Customer return, order '.$order->number.': '.$reason, $order->ulid);
                }
            }

            if ($order->items->every(fn (OrderItem $item): bool => $item->quantity_returned === $item->quantity)) {
                $this->transitions->apply($order, Returned::class, $reason, $actor);
            }

            return $return;
        });
    }
}
