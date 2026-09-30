<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

/**
 * Minimal product facts for other modules (reviews, wishlist).
 */
interface ProductDirectory
{
    /**
     * Published, active and not deleted — what a shopper can see.
     */
    public function isLive(string $productUlid): bool;

    public function titleOf(string $productUlid): ?string;
}
