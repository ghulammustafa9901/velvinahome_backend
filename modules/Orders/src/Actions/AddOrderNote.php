<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderNote;
use Modules\Orders\Support\OrderTransitions;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class AddOrderNote implements Action
{
    use ChecksPermission;

    public function handle(string $orderUlid, string $body, ?Authorizable $actor = null): OrderNote
    {
        $this->authorizePermission($actor, 'orders.manage');

        $order = Order::query()->where('ulid', $orderUlid)->firstOrFail();

        return OrderNote::query()->create([
            'order_id' => $order->id,
            'author_ulid' => OrderTransitions::actorUlid($actor),
            'body' => mb_substr(trim($body), 0, 5000),
        ]);
    }
}
