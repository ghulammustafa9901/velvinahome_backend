<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use Modules\Payments\Data\InboundWebhook;
use Modules\Support\ValueObjects\Money;

/**
 * One adapter per online provider (PayFast, JazzCash, Easypaisa, …), configured in the admin with
 * the store's own credentials (spec §6.6). Not implemented yet: the owner chose COD and manual
 * transfer for launch. The intake, ledger and confirmation flow already assume this shape.
 */
interface PaymentGateway
{
    public function code(): string;

    /**
     * Start a hosted-page payment; returns where to send the customer. A redirect back is
     * never proof of payment.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{redirect_url: string, gateway_reference: string}
     */
    public function initiate(string $paymentUlid, Money $amount, array $credentials, string $returnUrl): array;

    /**
     * Verify signature, timestamp window and payload of an inbound webhook.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{event_id: string, payment_ulid: string, status: 'paid'|'failed', amount: int, currency: string}|null null when invalid
     */
    public function verifyWebhook(InboundWebhook $webhook, array $credentials): ?array;

    /**
     * Server-side status inquiry, for reconciliation when callbacks are unreliable.
     *
     * @param  array<string, mixed>  $credentials
     * @return 'paid'|'failed'|'pending'
     */
    public function queryStatus(string $gatewayReference, array $credentials): string;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function refund(string $gatewayReference, Money $amount, array $credentials): string;
}
