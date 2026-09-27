<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Orders\Models\Order;
use Modules\Orders\States\Cancelled;
use Modules\Payments\Contracts\Payments;
use Modules\Pricing\Contracts\Coupons;

/**
 * The effects of cancelling an order before anything has shipped (ADR-0010): stock still
 * only reserved is released; stock already committed (COD, or a verified transfer) comes back
 * with a `return` movement; the coupon use is given back; unpaid payment attempts close.
 * Money already received is not refunded automatically: staff record the refund they made.
 *
 * Callers authorise the actor and hold the order's row lock inside their transaction
 * (CancelOrder, the COD "fake" outcome, a rejected transfer, the unpaid-order sweep).
 */
final class OrderCanceller
{
    public function __construct(
        private readonly StockReservations $stock,
        private readonly Coupons $coupons,
        private readonly Payments $payments,
        private readonly OrderTransitions $transitions,
        private readonly PaymentStatusMirror $paymentStatus,
    ) {}

    public function cancel(Order $order, string $reason, ?Authorizable $actor): Order
    {
        if (! $order->status->cancellable()) {
            throw ValidationException::withMessages(['status' => __('Only orders that have not shipped can be cancelled.')]);
        }

        foreach ($order->items as $item) {
            if ($item->reservation_ulid === null) {
                continue;
            }

            match ($this->stock->status($item->reservation_ulid)) {
                'active' => $this->stock->release($item->reservation_ulid),
                'converted' => $this->stock->restock($item->reservation_ulid, $item->quantity, 'Order '.$order->number.' cancelled', $order->ulid),
                default => null,
            };
        }

        $this->coupons->void($order->ulid);
        $this->payments->cancelOpen($order->ulid);

        $order->forceFill(['cancel_reason' => $reason]);
        $this->transitions->apply($order, Cancelled::class, $reason, $actor);
        $this->paymentStatus->sync($order);

        return $order;
    }
}
