<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Contracts\Outbox;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderStateTransition;
use Modules\Orders\States\Cancelled;
use Modules\Orders\States\Completed;
use Modules\Orders\States\Confirmed;
use Modules\Orders\States\Delivered;
use Modules\Orders\States\Fulfilled;
use Modules\Orders\States\OrderState;
use Modules\Orders\States\Returned;

/**
 * The single way an order changes state: the machine must allow it, the move is appended to
 * the timeline, milestone timestamps are set, and customer-facing moves queue an outbox event
 * — all inside the caller's transaction.
 */
final class OrderTransitions
{
    /** @var array<class-string<OrderState>, array{0: string|null, 1: string|null}> state => [timestamp column, outbox event] */
    private const EFFECTS = [
        Confirmed::class => ['confirmed_at', 'order.confirmed'],
        Fulfilled::class => ['fulfilled_at', null],
        Delivered::class => ['delivered_at', 'order.delivered'],
        Completed::class => ['completed_at', null],
        Cancelled::class => ['cancelled_at', 'order.cancelled'],
        Returned::class => [null, 'order.returned'],
    ];

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @param  class-string<OrderState>  $to
     *
     * @throws ValidationException when the machine does not allow the move
     */
    public function apply(Order $order, string $to, ?string $reason = null, ?Authorizable $actor = null): void
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => __('An order that is :from cannot become :to.', ['from' => $from->label(), 'to' => (new $to($order))->label()]),
            ]);
        }

        [$timestampColumn, $event] = self::EFFECTS[$to] ?? [null, null];

        if ($timestampColumn !== null) {
            $order->setAttribute($timestampColumn, now());
        }

        $order->status->transitionTo($to);

        self::record($order, $from::getMorphClass(), $to::getMorphClass(), $reason, $actor);

        if ($event !== null) {
            $this->outbox->record($event, ['order_ulid' => $order->ulid, 'reason' => $reason]);
        }
    }

    public static function record(Order $order, ?string $from, string $to, ?string $reason, ?Authorizable $actor): void
    {
        OrderStateTransition::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_type' => $actor === null ? 'system' : 'staff',
            'actor_ulid' => self::actorUlid($actor),
        ]);
    }

    public static function actorUlid(?Authorizable $actor): ?string
    {
        $ulid = $actor instanceof Model ? $actor->getAttribute('ulid') : null;

        return is_string($ulid) ? $ulid : null;
    }
}
