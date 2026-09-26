<?php

declare(strict_types=1);

namespace Modules\Integrations\Contracts;

/**
 * Performs the external effect for one outbox event type. Must be idempotent: a message is
 * delivered at least once, so a retry after a crash between "sent" and "marked published"
 * must not send twice to a system that cannot deduplicate by the message ULID.
 */
interface OutboxHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $messageUlid, array $payload): void;
}
