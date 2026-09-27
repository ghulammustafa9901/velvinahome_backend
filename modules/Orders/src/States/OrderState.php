<?php

declare(strict_types=1);

namespace Modules\Orders\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * The order state machine (spec §5 Orders, docs/plans/phase-3.md). Refunds are not order
 * states: payment and order are separate machines (spec §6.6); the order carries a
 * `payment_status` mirror instead.
 *
 * @extends State<\Modules\Orders\Models\Order>
 */
abstract class OrderState extends State
{
    /**
     * Stored value, e.g. "awaiting_confirmation".
     *
     * @var string
     */
    public static $name;

    abstract public function label(): string;

    /**
     * The label for a stored state name, e.g. from the timeline.
     */
    public static function labelFor(string $name, \Modules\Orders\Models\Order $order): string
    {
        $class = self::resolveStateClass($name);

        return is_string($class) && is_subclass_of($class, self::class) ? (new $class($order))->label() : $name;
    }

    /**
     * Before anything has left the warehouse, an order can still be cancelled outright.
     */
    public function cancellable(): bool
    {
        return false;
    }

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(AwaitingConfirmation::class)
            ->registerState([
                PendingPayment::class, AwaitingConfirmation::class, Confirmed::class, Processing::class,
                PartiallyFulfilled::class, Fulfilled::class, Delivered::class, Completed::class,
                Cancelled::class, Returned::class,
            ])
            ->allowTransition(PendingPayment::class, Confirmed::class)
            ->allowTransition(PendingPayment::class, Cancelled::class)
            ->allowTransition(AwaitingConfirmation::class, Confirmed::class)
            ->allowTransition(AwaitingConfirmation::class, Cancelled::class)
            ->allowTransition(Confirmed::class, Processing::class)
            ->allowTransition(Confirmed::class, PartiallyFulfilled::class)
            ->allowTransition(Confirmed::class, Fulfilled::class)
            ->allowTransition(Confirmed::class, Cancelled::class)
            ->allowTransition(Processing::class, PartiallyFulfilled::class)
            ->allowTransition(Processing::class, Fulfilled::class)
            ->allowTransition(Processing::class, Cancelled::class)
            ->allowTransition(PartiallyFulfilled::class, Fulfilled::class)
            ->allowTransition(Fulfilled::class, Delivered::class)
            // Return to origin: the courier could not deliver (common with COD).
            ->allowTransition(Fulfilled::class, Returned::class)
            ->allowTransition(Delivered::class, Completed::class)
            ->allowTransition(Delivered::class, Returned::class)
            ->allowTransition(Completed::class, Returned::class);
    }
}
