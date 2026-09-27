<?php

declare(strict_types=1);

namespace Modules\Orders\Listeners;

use Modules\Inventory\Contracts\StockReservations;
use Modules\Orders\States\Confirmed;
use Modules\Orders\States\PendingPayment;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Orders\Support\PaymentStatusMirror;
use Modules\Payments\Events\PaymentConfirmed;

/**
 * Runs synchronously inside the transaction that captured the payment: a prepaid order is
 * confirmed and its reserved stock committed together with the money, or none of it is.
 */
final class ConfirmPaidOrder
{
    public function __construct(
        private readonly StockReservations $stock,
        private readonly OrderTransitions $transitions,
        private readonly PaymentStatusMirror $paymentStatus,
    ) {}

    public function handle(PaymentConfirmed $event): void
    {
        $order = OrderLock::take($event->orderUlid);

        if ($order->status instanceof PendingPayment) {
            foreach ($order->items as $item) {
                if ($item->reservation_ulid !== null && $this->stock->status($item->reservation_ulid) === 'active') {
                    $this->stock->commit($item->reservation_ulid, $order->ulid);
                }
            }

            $this->transitions->apply($order, Confirmed::class, 'Payment received');
        }

        $this->paymentStatus->sync($order);
    }
}
