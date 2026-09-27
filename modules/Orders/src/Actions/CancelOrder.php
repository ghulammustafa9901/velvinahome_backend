<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Models\Order;
use Modules\Orders\Support\OrderCanceller;
use Modules\Orders\Support\OrderLock;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Staff cancel an order that has not shipped. See OrderCanceller for what that undoes.
 */
final class CancelOrder implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly OrderCanceller $canceller,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $orderUlid, string $reason, ?Authorizable $actor = null): Order
    {
        $this->authorizePermission($actor, 'orders.manage');

        return $this->db->connection()->transaction(
            fn (): Order => $this->canceller->cancel(OrderLock::take($orderUlid), $reason, $actor),
        );
    }
}
