<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

use Illuminate\Support\Facades\Http;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Settings\Contracts\StoreSettings;
use RuntimeException;

/**
 * Outbox handler for `storefront.revalidate`: POSTs the tags to the storefront's
 * `/api/revalidate` (from the store URL setting), signed with STOREFRONT_REVALIDATE_SECRET
 * (HMAC over timestamp and body). Idempotent by nature: purging a tag twice is harmless.
 * Without a secret or a store URL nothing is sent.
 */
final class RevalidateStorefront implements OutboxHandler
{
    public function __construct(private readonly StoreSettings $settings) {}

    public function handle(string $messageUlid, array $payload): void
    {
        $secret = config('store.storefront.revalidate_secret');
        $origin = $this->settings->storefrontUrl();

        if (! is_string($secret) || strlen($secret) < 32 || $origin === null) {
            return;
        }

        $url = config('store.storefront.revalidate_url') ?: $origin.'/api/revalidate';
        $body = (string) json_encode([
            'tags' => array_values(array_filter((array) ($payload['tags'] ?? []), 'is_string')),
            'message' => $messageUlid,
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = Http::timeout(5)
            ->withHeaders([
                'X-Revalidate-Timestamp' => $timestamp,
                'X-Revalidate-Signature' => hash_hmac('sha256', $timestamp."\n".$body, $secret),
            ])
            ->withBody($body, 'application/json')
            ->post((string) $url);

        if (! $response->successful()) {
            throw new RuntimeException('Storefront revalidation failed with HTTP '.$response->status().'.');
        }
    }
}
