<?php

declare(strict_types=1);

namespace Modules\Integrations\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Integrations\Models\OutboxMessage;
use Modules\Integrations\Support\OutboxHandlerRegistry;
use Modules\Support\Contracts\Action;
use Throwable;

/**
 * Publishes due outbox messages for the current tenant.
 *
 * Each message is claimed with `FOR UPDATE SKIP LOCKED` inside its own short transaction, so
 * concurrent publishers never deliver the same message twice at the same time, and one
 * failing message never blocks the rest. Failures back off exponentially, and are retried only
 * after every new message.
 */
final class PublishPendingOutboxMessages implements Action
{
    private const BATCH = 100;

    private const MAX_BACKOFF_SECONDS = 3600;

    public function __construct(
        private readonly OutboxHandlerRegistry $handlers,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{published: int, failed: int, unhandled: int}
     */
    public function handle(): array
    {
        $stats = ['published' => 0, 'failed' => 0, 'unhandled' => 0];

        for ($i = 0; $i < self::BATCH; $i++) {
            $outcome = $this->db->connection()->transaction(function (): ?string {
                /** @var OutboxMessage|null $message */
                // Never-tried messages first: a backlog of failing retries (a storefront that
                // is down) must not hold back an order email recorded a moment ago.
                $message = OutboxMessage::query()
                    ->whereNull('published_at')
                    ->where('available_at', '<=', now())
                    ->orderBy('attempts')
                    ->orderBy('id')
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if ($message === null) {
                    return null;
                }

                $handler = $this->handlers->for($message->event_type);

                if ($handler === null) {
                    $this->defer($message, 'No handler registered for '.$message->event_type);

                    return 'unhandled';
                }

                try {
                    $handler->handle($message->ulid, $message->payload);
                } catch (Throwable $exception) {
                    $this->defer($message, $exception->getMessage());

                    return 'failed';
                }

                $message->forceFill([
                    'published_at' => now(),
                    'attempts' => $message->attempts + 1,
                    'last_error' => null,
                ])->save();

                return 'published';
            });

            if ($outcome === null) {
                break;
            }

            $stats[$outcome]++;
        }

        return $stats;
    }

    private function defer(OutboxMessage $message, string $error): void
    {
        $attempts = $message->attempts + 1;

        $message->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 2000),
            'available_at' => now()->addSeconds(min(2 ** $attempts * 10, self::MAX_BACKOFF_SECONDS)),
        ])->save();
    }
}
