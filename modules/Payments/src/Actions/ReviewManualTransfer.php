<?php

declare(strict_types=1);

namespace Modules\Payments\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Events\PaymentFailed;
use Modules\Payments\Exceptions\PaymentRuleViolation;
use Modules\Payments\Models\Payment;
use Modules\Payments\Support\CapturePayment;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Staff check the bank/wallet statement and accept or reject a transfer. Accepting captures
 * the payment and — through PaymentConfirmed, in the same transaction — confirms the order and
 * commits its stock. Rejecting fails the payment; Orders cancels the order and frees the stock.
 */
final class ReviewManualTransfer implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly CapturePayment $capture,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $orderUlid, bool $accept, ?string $bankReference, ?string $reason, ?Authorizable $actor = null): Payment
    {
        $this->authorizePermission($actor, 'orders.manage');

        return $this->db->connection()->transaction(function () use ($orderUlid, $accept, $bankReference, $reason, $actor): Payment {
            $payment = Payment::query()
                ->where('order_ulid', $orderUlid)
                ->where('method_code', 'manual_transfer')
                ->whereIn('status', ['awaiting_verification', 'pending'])
                ->lockForUpdate()
                ->latest('id')
                ->first() ?? throw PaymentRuleViolation::notAwaitingVerification();

            $actorUlid = $actor instanceof Model ? $actor->getAttribute('ulid') : null;

            if ($accept) {
                $this->capture->handle($payment, 'Transfer verified by staff', $bankReference, is_string($actorUlid) ? $actorUlid : null);
            } else {
                $payment->forceFill(['status' => 'failed', 'failure_reason' => $reason ?? 'Transfer not received'])->save();
                PaymentFailed::dispatch($payment->order_ulid, $payment->ulid, (string) $payment->failure_reason);
            }

            return $payment->refresh();
        });
    }
}
