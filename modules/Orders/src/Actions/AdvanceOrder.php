<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Models\Order;
use Modules\Orders\States\Completed;
use Modules\Orders\States\Processing;
use Modules\Orders\Support\OrderLock;
use Modules\Orders\Support\OrderTransitions;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Simple forward moves with no side effects beyond the timeline: start packing, or close a
 * delivered order.
 */
final class AdvanceOrder implements Action
{
    use ChecksPermission;

    public const TARGETS = ['processing' => Processing::class, 'completed' => Completed::class];

    public function __construct(
        private readonly OrderTransitions $transitions,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  key-of<self::TARGETS>  $to
     */
    public function handle(string $orderUlid, string $to, ?Authorizable $actor = null): Order
    {
        $this->authorizePermission($actor, 'orders.manage');

        return $this->db->connection()->transaction(function () use ($orderUlid, $to, $actor): Order {
            $order = OrderLock::take($orderUlid);
            $this->transitions->apply($order, self::TARGETS[$to], null, $actor);

            return $order;
        });
    }
}
