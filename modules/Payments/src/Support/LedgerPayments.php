<?php

declare(strict_types=1);

namespace Modules\Payments\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Payments\Actions\ReviewManualTransfer;
use Modules\Payments\Actions\SubmitTransferProof;
use Modules\Payments\Contracts\Payments;
use Modules\Payments\Exceptions\PaymentRuleViolation;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentMethod;
use Modules\Payments\Models\PaymentTransaction;
use Modules\Support\ValueObjects\Money;

/**
 * Payment attempts plus an append-only transaction ledger. Balances on `payments` are
 * always the sum of their transactions; they are updated in the same transaction that
 * appends the ledger row.
 */
final class LedgerPayments implements Payments
{
    public function __construct(
        private readonly CapturePayment $capture,
        private readonly SubmitTransferProof $submitProof,
        private readonly ReviewManualTransfer $review,
    ) {}

    public function enabledMethods(): array
    {
        $methods = [];

        foreach (PaymentMethod::query()->where('is_enabled', true)->orderBy('position')->get() as $method) {
            $methods[] = [
                'code' => $method->code,
                'label' => $method->label,
                'description' => $method->description,
                'instructions' => self::instructions($method),
            ];
        }

        return $methods;
    }

    private static function instructions(?PaymentMethod $method): ?string
    {
        $instructions = $method?->config['instructions'] ?? null;

        return is_string($instructions) && trim($instructions) !== '' ? $instructions : null;
    }

    public function isEnabled(string $methodCode): bool
    {
        return PaymentMethod::query()->where('code', $methodCode)->where('is_enabled', true)->exists();
    }

    public function open(string $orderUlid, string $methodCode, Money $amount, string $idempotencyKey): string
    {
        return Payment::query()->create([
            'order_ulid' => $orderUlid,
            'method_code' => $methodCode,
            'status' => 'pending',
            'amount' => $amount,
            'idempotency_key' => $idempotencyKey,
        ])->ulid;
    }

    public function recordCodCollected(string $orderUlid, ?string $actorUlid): void
    {
        $payment = Payment::query()->where('order_ulid', $orderUlid)->where('method_code', 'cod')->where('status', 'pending')->lockForUpdate()->first();

        if ($payment === null) {
            return;
        }

        $this->capture->handle($payment, 'COD collected on delivery', null, $actorUlid);
    }

    public function submitTransferProof(string $orderUlid, UploadedFile $receipt, ?string $reference): void
    {
        $this->submitProof->handle($orderUlid, $receipt, $reference);
    }

    public function reviewTransfer(string $orderUlid, bool $accept, ?string $bankReference, ?string $reason, ?Authorizable $actor): void
    {
        $this->review->handle($orderUlid, $accept, $bankReference, $reason, $actor);
    }

    public function proofUrl(string $orderUlid): ?string
    {
        $path = Payment::query()->where('order_ulid', $orderUlid)->whereNotNull('proof_path')->latest('id')->value('proof_path');

        if (! is_string($path)) {
            return null;
        }

        $disk = Storage::disk('tenant_private');

        return $disk->providesTemporaryUrls() ? $disk->temporaryUrl($path, now()->addMinutes(10)) : null;
    }

    public function refund(string $orderUlid, Money $amount, string $reason, string $idempotencyKey, ?string $actorUlid): bool
    {
        if (PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return false;
        }

        $payment = Payment::query()->where('order_ulid', $orderUlid)->whereIn('status', ['paid', 'partially_refunded'])
            ->lockForUpdate()->latest('id')->first() ?? throw PaymentRuleViolation::noPayment();

        $refundable = $payment->paid()->minus($payment->refunded());

        if (! $amount->isPositive() || $amount->greaterThan($refundable)) {
            throw PaymentRuleViolation::refundExceedsPaid($refundable->format());
        }

        PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => 'refund',
            'amount' => $amount,
            'idempotency_key' => $idempotencyKey,
            'note' => $reason,
            'actor_ulid' => $actorUlid,
        ]);

        $refunded = $payment->refunded_amount + $amount->amount;

        $payment->forceFill([
            'refunded_amount' => $refunded,
            'status' => $refunded >= $payment->paid_amount ? 'refunded' : 'partially_refunded',
        ])->save();

        return true;
    }

    public function cancelOpen(string $orderUlid): void
    {
        DB::table('payments')->where('order_ulid', $orderUlid)->whereIn('status', ['pending', 'awaiting_verification'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    public function summary(string $orderUlid): ?array
    {
        $payment = Payment::query()->where('order_ulid', $orderUlid)->latest('id')->first();

        if ($payment === null) {
            return null;
        }

        $method = PaymentMethod::query()->where('code', $payment->method_code)->first();

        return [
            'status' => $payment->status,
            'method' => $payment->method_code,
            'paid' => $payment->paid(),
            'refunded' => $payment->refunded(),
            'awaiting_verification' => $payment->status === 'awaiting_verification',
            'instructions' => self::instructions($method),
        ];
    }
}
