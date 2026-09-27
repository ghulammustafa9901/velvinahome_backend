<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Orders\Models\Order;
use Modules\Orders\States\PendingPayment;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Orders\Support\PaymentStatusMirror;
use Modules\Payments\Contracts\Payments;
use Modules\Support\Contracts\Action;

/**
 * The customer uploads their transfer receipt for an order awaiting payment. Access to the
 * order is checked by the caller (order token or the owning customer).
 */
final class SubmitPaymentProof implements Action
{
    public function __construct(
        private readonly Payments $payments,
        private readonly PaymentStatusMirror $paymentStatus,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(Order $order, UploadedFile $receipt, ?string $reference): Order
    {
        return $this->db->connection()->transaction(function () use ($order, $receipt, $reference): Order {
            $order = OrderLock::take($order->ulid);

            if (! $order->status instanceof PendingPayment) {
                throw ValidationException::withMessages(['receipt' => __('This order is not waiting for a payment.')]);
            }

            $this->payments->submitTransferProof($order->ulid, $receipt, $reference);
            $this->paymentStatus->sync($order);
            OrderTransitions::record($order, 'pending_payment', 'pending_payment', 'Customer uploaded a transfer receipt', null);

            return $order;
        });
    }
}
