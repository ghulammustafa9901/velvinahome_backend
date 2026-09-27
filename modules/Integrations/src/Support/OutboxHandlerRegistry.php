<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Integrations\Contracts\OutboxHandlers;

/**
 * event type => handler class. Modules register their handlers from their service
 * providers; an event type without a handler stays pending and is reported, never dropped.
 */
final class OutboxHandlerRegistry implements OutboxHandlers
{
    /** @var array<string, class-string<OutboxHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<OutboxHandler>  $handler
     */
    public function register(string $eventType, string $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    public function for(string $eventType): ?OutboxHandler
    {
        $class = $this->handlers[$eventType] ?? null;

        if ($class === null) {
            return null;
        }

        /** @var OutboxHandler */
        return $this->container->make($class);
    }
}
