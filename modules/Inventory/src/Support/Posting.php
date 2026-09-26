<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Modules\Support\ValueObjects\Money;

/**
 * Why a movement happened and who caused it — written onto every ledger row.
 */
final readonly class Posting
{
    public function __construct(
        public ?string $referenceType = null,
        public ?string $referenceUlid = null,
        public ?string $reason = null,
        public ?string $actorUlid = null,
        public ?Money $unitCost = null,
    ) {}
}
