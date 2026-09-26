<?php

declare(strict_types=1);

namespace Modules\Integrations\Contracts;

/**
 * Record a domain event that has an effect outside this database (email, webhook, courier
 * booking, search indexing).
 *
 * Call it inside the business transaction. The message becomes visible only if that
 * transaction commits, and it is published by a queued job dispatched after commit.
 */
interface Outbox
{
    /**
     * @param  array<string, mixed>  $payload  plain data only — ids (ULIDs) and values, never models
     */
    public function record(string $eventType, array $payload): string;
}
