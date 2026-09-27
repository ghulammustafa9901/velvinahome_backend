<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Integrations\Contracts\Outbox;
use Modules\Orders\Models\Order;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Orders\Support\PaymentStatusMirror;
use Modules\Payments\Contracts\Payments;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\ValueObjects\Money;

/**
 * Record money given back to the customer (bank transfer, wallet, cash). Never more than was
 * paid; the same idempotency key never refunds twice (a double-clicked button is one refund).
 */
final class RefundOrder implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly Payments $payments,
        private readonly PaymentStatusMirror $paymentStatus,
        private readonly Outbox $outbox,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $orderUlid, Money $amount, string $reason, string $idempotencyKey, ?Authorizable $actor = null): Order
    {
        $this->authorizePermission($actor, 'orders.refund');

        return $this->db->connection()->transaction(function () use ($orderUlid, $amount, $reason, $idempotencyKey, $actor): Order {
            $order = OrderLock::take($orderUlid);

            if (! $this->payments->refund($order->ulid, $amount, $reason, $idempotencyKey, OrderTransitions::actorUlid($actor))) {
                return $order;
            }

            $this->paymentStatus->sync($order);

            $status = $order->status::getMorphClass();
            OrderTransitions::record($order, $status, $status, 'Refunded '.$amount->format().': '.$reason, $actor);
            $this->outbox->record('order.refunded', ['order_ulid' => $order->ulid, 'amount' => $amount->amount, 'currency' => $amount->currency->value]);

            return $order;
        });
    }
}
