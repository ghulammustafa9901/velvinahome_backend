<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

use Modules\Integrations\Contracts\Outbox;
use Modules\Integrations\Jobs\PublishOutboxMessages;
use Modules\Integrations\Models\OutboxMessage;

final class DatabaseOutbox implements Outbox
{
    public function record(string $eventType, array $payload): string
    {
        $message = OutboxMessage::query()->create([
            'event_type' => $eventType,
            'payload' => $payload,
            'available_at' => now(),
        ]);

        // The job is ShouldQueueAfterCommit: held until the surrounding transaction commits,
        // and discarded if it rolls back.
        PublishOutboxMessages::dispatch();

        return $message->ulid;
    }
}
