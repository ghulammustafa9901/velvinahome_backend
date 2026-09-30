<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * Opening stock for migrated products: recorded as a stock count at a location, so the
 * ledger explains every unit (spec §6.5).
 */
interface StockImport
{
    /**
     * Set the counted on-hand quantity of each variant at the location (the default location
     * when none is given). Returns how many variants changed.
     *
     * @param  array<string, int>  $quantities  variant ULID => counted quantity (>= 0)
     */
    public function count(array $quantities, string $note, ?string $locationUlid = null): int;
}
