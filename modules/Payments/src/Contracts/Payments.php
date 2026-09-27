<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\UploadedFile;
use Modules\Support\ValueObjects\Money;

/**
 * What Checkout and Orders may ask of Payments. All methods join the caller's transaction.
 */
interface Payments
{
    /**
     * @return list<array{code: string, label: string, description: string|null, instructions: string|null}>
     */
    public function enabledMethods(): array;

    public function isEnabled(string $methodCode): bool;

    /**
     * Open the payment attempt for a newly placed order.
     *
     * @return string payment ULID
     */
    public function open(string $orderUlid, string $methodCode, Money $amount, string $idempotencyKey): string;

    /**
     * COD money collected by the courier and confirmed (usually on delivery).
     */
    public function recordCodCollected(string $orderUlid, ?string $actorUlid): void;

    /**
     * The customer's receipt for a manual transfer; the payment then waits for staff.
     *
     * @throws \Illuminate\Validation\ValidationException for a bad file or no transfer awaited
     */
    public function submitTransferProof(string $orderUlid, UploadedFile $receipt, ?string $reference): void;

    /**
     * Staff accept (capture, PaymentConfirmed) or reject (PaymentFailed) a manual transfer.
     *
     * @throws \Illuminate\Validation\ValidationException when no transfer is waiting
     */
    public function reviewTransfer(string $orderUlid, bool $accept, ?string $bankReference, ?string $reason, ?Authorizable $actor): void;

    /**
     * A short-lived link for staff to view an uploaded transfer receipt.
     */
    public function proofUrl(string $orderUlid): ?string;

    /**
     * Refund part or all of what was paid. Idempotent per key.
     *
     * @return bool false when this key was already refunded (a replay)
     *
     * @throws \Illuminate\Validation\ValidationException when the amount exceeds what is refundable
     */
    public function refund(string $orderUlid, Money $amount, string $reason, string $idempotencyKey, ?string $actorUlid): bool;

    /**
     * Close unpaid attempts of a cancelled order.
     */
    public function cancelOpen(string $orderUlid): void;

    /**
     * @return array{status: string, method: string, paid: Money, refunded: Money, awaiting_verification: bool, instructions: string|null}|null
     */
    public function summary(string $orderUlid): ?array;
}
