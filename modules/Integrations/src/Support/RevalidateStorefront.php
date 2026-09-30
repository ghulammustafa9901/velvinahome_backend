<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

use Illuminate\Support\Facades\Http;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Platform\Contracts\TenantDirectory;
use RuntimeException;

/**
 * Outbox handler for `storefront.revalidate`: POSTs the tags to the brand storefront's
 * `/api/revalidate`, signed with STOREFRONT_REVALIDATE_SECRET (HMAC over timestamp and body).
 * The storefront scopes every tag to the brand named in the signed body. Idempotent by nature:
 * purging a tag twice is harmless. Without a secret configured nothing is sent.
 */
final class RevalidateStorefront implements OutboxHandler
{
    public function __construct(private readonly TenantDirectory $tenants) {}

    public function handle(string $messageUlid, array $payload): void
    {
        $secret = config('platform.storefront.revalidate_secret');
        $origin = $this->tenants->storefrontUrl();

        if (! is_string($secret) || strlen($secret) < 32 || $origin === null) {
            return;
        }

        $url = config('platform.storefront.revalidate_url') ?: $origin.'/api/revalidate';
        $body = (string) json_encode([
            'site' => (string) parse_url($origin, PHP_URL_HOST),
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
