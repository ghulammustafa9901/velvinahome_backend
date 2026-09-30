<?php

declare(strict_types=1);

namespace Modules\Customers\Actions;

use Modules\Customers\Models\WishlistItem;
use Modules\Support\Contracts\Action;

final class RemoveFromWishlist implements Action
{
    public function handle(int $customerId, string $itemUlid): void
    {
        WishlistItem::query()->where('customer_id', $customerId)->where('ulid', $itemUlid)->firstOrFail()->delete();
    }
}
