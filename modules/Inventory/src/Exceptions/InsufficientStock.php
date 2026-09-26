<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use DomainException;

/**
 * Maps to `409 stock_unavailable` at the API (api-conventions §3).
 */
final class InsufficientStock extends DomainException
{
    public static function for(string $sku, int $requested, int $available): self
    {
        return new self("Only {$available} of {$sku} available; {$requested} requested.");
    }
}
