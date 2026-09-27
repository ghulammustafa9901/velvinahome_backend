<?php

declare(strict_types=1);

namespace Modules\Payments\Support;

use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentTransaction;

/**
 * Record a capture and announce it. Shared by COD collection and transfer verification (and
 * gateway webhooks later). Runs inside the caller's transaction, so `PaymentConfirmed`
 * listeners (order confirmation, stock commit) commit or roll back with it.
 */
final class CapturePayment
{
    public function handle(Payment $payment, string $note, ?string $reference, ?string $actorUlid): void
    {
        PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => 'capture',
            'amount' => $payment->amount,
            'reference' => $reference,
            'note' => $note,
            'actor_ulid' => $actorUlid,
        ]);

        $payment->forceFill([
            'status' => 'paid',
            'paid_amount' => $payment->amount->amount,
            'confirmed_at' => now(),
            'gateway_reference' => $reference ?? $payment->gateway_reference,
        ])->save();

        PaymentConfirmed::dispatch($payment->order_ulid, $payment->ulid, $payment->method_code, $payment->amount->amount);
    }
}
