<?php

declare(strict_types=1);

namespace Modules\Integrations\Contracts;

/**
 * How other modules subscribe a handler to an outbox event type, from their service
 * provider's boot.
 */
interface OutboxHandlers
{
    /**
     * @param  class-string<OutboxHandler>  $handler
     */
    public function register(string $eventType, string $handler): void;
}
