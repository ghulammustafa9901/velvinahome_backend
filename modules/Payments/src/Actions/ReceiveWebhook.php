<?php

declare(strict_types=1);

namespace Modules\Payments\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Data\InboundWebhook;
use Modules\Payments\Models\PaymentMethod;
use Modules\Payments\Support\GatewayRegistry;
use Modules\Support\Contracts\Action;

/**
 * Stores every verified inbound webhook raw, deduplicated by the provider's event id
 * (spec §6.6, §8). Processing is a separate, idempotent step so a replayed or duplicated
 * callback can never pay an order twice.
 *
 * With no online gateway configured (owner decision for launch), every provider is unknown
 * and the endpoint answers 404.
 */
final class ReceiveWebhook implements Action
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    public function handle(InboundWebhook $webhook): bool
    {
        $gateway = $this->gateways->get($webhook->provider);
        $method = PaymentMethod::query()->where('code', $webhook->provider)->where('is_enabled', true)->first();

        abort_if($gateway === null || $method === null, 404);

        $event = $gateway->verifyWebhook($webhook, $method->credentials ?? []);
        abort_if($event === null, 400, 'Invalid signature.');

        try {
            DB::table('webhook_events')->insert([
                'ulid' => (string) Str::ulid(),
                'provider' => $webhook->provider,
                'provider_event_id' => $event['event_id'],
                'signature_verified' => true,
                'payload' => json_encode($webhook->payload, JSON_THROW_ON_ERROR),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
