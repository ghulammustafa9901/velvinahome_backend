<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Orders\Models\Order;
use Modules\Orders\States\AwaitingConfirmation;
use Modules\Orders\States\Confirmed;
use Modules\Orders\Support\OrderCanceller;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * The COD confirmation call before dispatch (spec §5): the customer confirmed, could not be
 * reached (try again later), or the order is fake (cancelled, stock back on sale).
 */
final class ConfirmCodOrder implements Action
{
    public const OUTCOMES = ['confirmed', 'unreachable', 'fake'];

    use ChecksPermission;

    public function __construct(
        private readonly OrderTransitions $transitions,
        private readonly OrderCanceller $canceller,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $orderUlid, string $outcome, ?string $note = null, ?Authorizable $actor = null): Order
    {
        $this->authorizePermission($actor, 'orders.confirm_cod');

        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => __('Unknown confirmation outcome.')]);
        }

        return $this->db->connection()->transaction(function () use ($orderUlid, $outcome, $note, $actor): Order {
            $order = OrderLock::take($orderUlid);

            if (! $order->status instanceof AwaitingConfirmation) {
                throw ValidationException::withMessages(['status' => __('This order is not waiting for a confirmation call.')]);
            }

            $order->forceFill(['cod_confirmation' => $outcome, 'cod_attempts' => $order->cod_attempts + 1]);

            return match ($outcome) {
                'confirmed' => $this->confirm($order, $note, $actor),
                'unreachable' => $this->unreachable($order, $note, $actor),
                default => $this->fake($order, $note, $actor),
            };
        });
    }

    private function confirm(Order $order, ?string $note, ?Authorizable $actor): Order
    {
        $this->transitions->apply($order, Confirmed::class, $note ?? 'COD confirmed by phone', $actor);

        return $order;
    }

    private function unreachable(Order $order, ?string $note, ?Authorizable $actor): Order
    {
        $order->save();
        OrderTransitions::record($order, 'awaiting_confirmation', 'awaiting_confirmation', trim('Customer unreachable (attempt '.$order->cod_attempts.'). '.($note ?? '')), $actor);

        return $order;
    }

    private function fake(Order $order, ?string $note, ?Authorizable $actor): Order
    {
        // Marking an order fake is part of the confirmation workflow, so orders.confirm_cod
        // (checked above) is enough to cancel it; the timeline records who did it.
        return $this->canceller->cancel($order, trim('Fake order (COD confirmation). '.($note ?? '')), $actor);
    }
}
