<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Actions\PublishPendingOutboxMessages;
use Modules\Integrations\Contracts\Outbox;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Integrations\Models\OutboxMessage;
use Modules\Integrations\Support\OutboxHandlerRegistry;
use Tests\Support\TestPlatform;

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

beforeEach(function (): void {
    RecordingOutboxHandler::$delivered = [];
    RecordingOutboxHandler::$fail = false;
    app(OutboxHandlerRegistry::class)->register('test.event', RecordingOutboxHandler::class);
    $this->tenant = TestPlatform::brand(TestPlatform::BRAND_B);
});

afterEach(function (): void {
    // Fixture tenants are reused across runs; leave no probe message behind to be picked
    // up by a later run's publisher.
    $this->tenant->run(function (): void {
        OutboxMessage::query()->where('event_type', 'test.event')->delete();
    });
});

it('records nothing and publishes nothing for a rolled-back transaction', function (): void {
    $ulid = $this->tenant->run(function (): ?string {
        $ulid = null;

        try {
            DB::transaction(function () use (&$ulid): void {
                $ulid = app(Outbox::class)->record('test.event', ['n' => 1]);

                throw new RuntimeException('business rule failed');
            });
        } catch (RuntimeException) {
        }

        return $ulid;
    });

    expect($ulid)->toBeString()
        ->and($this->tenant->run(fn (): bool => OutboxMessage::query()->where('ulid', $ulid)->exists()))->toBeFalse()
        ->and(RecordingOutboxHandler::$delivered)->toBe([]);
});

it('publishes a committed message exactly once', function (): void {
    $ulid = $this->tenant->run(fn (): string => DB::transaction(fn (): string => app(Outbox::class)->record('test.event', ['n' => 2])));

    // The sync queue already ran the after-commit publish job; a second sweep is a no-op.
    $this->tenant->run(function (): void {
        app(PublishPendingOutboxMessages::class)->handle();
    });

    expect(array_count_values(RecordingOutboxHandler::$delivered)[$ulid] ?? 0)->toBe(1)
        ->and($this->tenant->run(fn () => OutboxMessage::query()->where('ulid', $ulid)->value('published_at')))->not->toBeNull();
});

it('keeps a failed message pending with backoff instead of losing it', function (): void {
    RecordingOutboxHandler::$fail = true;

    $ulid = $this->tenant->run(fn (): string => app(Outbox::class)->record('test.event', ['n' => 3]));

    $this->tenant->run(function () use ($ulid): void {
        $message = OutboxMessage::query()->where('ulid', $ulid)->firstOrFail();

        expect($message->published_at)->toBeNull()
            ->and($message->attempts)->toBe(1)
            ->and($message->last_error)->toContain('downstream is down')
            ->and($message->available_at->isFuture())->toBeTrue();
    });
});
