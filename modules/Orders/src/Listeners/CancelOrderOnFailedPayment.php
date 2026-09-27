<?php

declare(strict_types=1);

namespace Modules\Orders\Listeners;

use Modules\Orders\States\PendingPayment;
use Modules\Orders\Support\OrderCanceller;
use Modules\Orders\Support\OrderLock;
use Modules\Payments\Events\PaymentFailed;

/**
 * A prepaid order whose payment was rejected is cancelled, which frees its reserved stock.
 */
final class CancelOrderOnFailedPayment
{
    public function __construct(private readonly OrderCanceller $canceller) {}

    public function handle(PaymentFailed $event): void
    {
        $order = OrderLock::take($event->orderUlid);

        if ($order->status instanceof PendingPayment) {
            $this->canceller->cancel($order, 'Payment not received: '.$event->reason, null);
        }
    }
}
