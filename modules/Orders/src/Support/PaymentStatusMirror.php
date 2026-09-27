<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Modules\Orders\Models\Order;
use Modules\Payments\Contracts\Payments;

/**
 * Copies the payment machine's state onto the order for listing and filtering. Payments stays
 * the source of truth.
 */
final class PaymentStatusMirror
{
    private const MAP = [
        'pending' => 'unpaid',
        'awaiting_verification' => 'awaiting_verification',
        'paid' => 'paid',
        'partially_refunded' => 'partially_refunded',
        'refunded' => 'refunded',
        'failed' => 'voided',
        'cancelled' => 'voided',
    ];

    public function __construct(private readonly Payments $payments) {}

    public function sync(Order $order): void
    {
        $status = $this->payments->summary($order->ulid)['status'] ?? null;

        if ($status !== null && isset(self::MAP[$status])) {
            $order->forceFill(['payment_status' => self::MAP[$status]])->save();
        }
    }
}
