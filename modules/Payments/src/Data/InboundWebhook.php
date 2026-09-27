<?php

declare(strict_types=1);

namespace Modules\Payments\Data;

/**
 * An inbound webhook exactly as received: the raw body (signatures are computed over bytes,
 * not over re-encoded JSON), the headers, and the decoded payload.
 */
final readonly class InboundWebhook
{
    /**
     * @param  array<string, list<string|null>>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $rawBody,
        public array $headers,
        public array $payload,
        public ?string $ipAddress,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
