<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Actions\PublishPendingOutboxMessages;
use Modules\Integrations\Contracts\Outbox;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Integrations\Models\OutboxMessage;
use Modules\Integrations\Support\OutboxHandlerRegistry;

/*
| Spec §6.11: nothing is published for a rolled-back transaction; nothing committed is lost;
| republishing is idempotent.
*/

final class RecordingOutboxHandler implements OutboxHandler
{
    /** @var list<string> */
    public static array $delivered = [];

    public static bool $fail = false;

    public function handle(string $messageUlid, array $payload): void
    {
        if (self::$fail) {
            throw new RuntimeException('downstream is down');
        }

        self::$delivered[] = $messageUlid;
    }
}

final class FailingOutboxHandler implements OutboxHandler
{
    public function handle(string $messageUlid, array $payload): void
    {
        throw new RuntimeException('storefront unreachable');
    }
}

beforeEach(function (): void {
    RecordingOutboxHandler::$delivered = [];
    RecordingOutboxHandler::$fail = false;
    app(OutboxHandlerRegistry::class)->register('test.event', RecordingOutboxHandler::class);
});

afterEach(function (): void {
    OutboxMessage::query()->where('event_type', 'test.event')->delete();
});

it('records nothing and publishes nothing for a rolled-back transaction', function (): void {
    $ulid = null;
    try {
        DB::transaction(function () use (&$ulid): void {
            $ulid = app(Outbox::class)->record('test.event', ['n' => 1]);

            throw new RuntimeException('business rule failed');
        });
    } catch (RuntimeException) {
    }
    expect($ulid)->toBeString()
        ->and(OutboxMessage::query()->where('ulid', $ulid)->exists())->toBeFalse()
        ->and(RecordingOutboxHandler::$delivered)->toBe([]);
});

it('publishes a committed message exactly once', function (): void {
    $ulid = DB::transaction(fn (): string => app(Outbox::class)->record('test.event', ['n' => 2]));

    app(PublishPendingOutboxMessages::class)->handle();

    expect(array_count_values(RecordingOutboxHandler::$delivered)[$ulid] ?? 0)->toBe(1)
        ->and(OutboxMessage::query()->where('ulid', $ulid)->value('published_at'))->not->toBeNull();
});

it('never lets a backlog of failing retries starve a new message', function (): void {
    // A storefront that is down leaves revalidations failing; an order email must still go out.
    app(OutboxHandlerRegistry::class)->register('test.retry', FailingOutboxHandler::class);
    $now = now();
    OutboxMessage::query()->insert(array_map(fn (int $i): array => [
        'ulid' => (string) Str::ulid(),
        'event_type' => 'test.retry',
        'payload' => json_encode(['n' => $i], JSON_THROW_ON_ERROR),
        'available_at' => $now->copy()->subMinute(),
        'attempts' => 3,
        'created_at' => $now->copy()->subHour(),
    ], range(1, 120)));
    $ulid = app(Outbox::class)->record('test.event', ['n' => 4]);

    try {
        expect(RecordingOutboxHandler::$delivered)->toContain($ulid);
    } finally {
        OutboxMessage::query()->where('event_type', 'test.retry')->delete();
    }
});

it('keeps a failed message pending with backoff instead of losing it', function (): void {
    RecordingOutboxHandler::$fail = true;

    $ulid = app(Outbox::class)->record('test.event', ['n' => 3]);
    $message = OutboxMessage::query()->where('ulid', $ulid)->firstOrFail();
    expect($message->published_at)->toBeNull()
        ->and($message->attempts)->toBe(1)
        ->and($message->last_error)->toContain('downstream is down')
        ->and($message->available_at->isFuture())->toBeTrue();
});
