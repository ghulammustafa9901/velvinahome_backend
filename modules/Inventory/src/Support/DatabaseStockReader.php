<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Modules\Inventory\Contracts\StockReader;
use Modules\Inventory\Models\StockLevel;

final class DatabaseStockReader implements StockReader
{
    public function available(array $variantIds): array
    {
        $available = array_fill_keys($variantIds, 0);

        if ($variantIds === []) {
            return $available;
        }

        $rows = StockLevel::query()
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->where('locations.is_active', true)
            ->whereIn('stock_levels.variant_id', $variantIds)
            ->groupBy('stock_levels.variant_id')
            ->selectRaw('stock_levels.variant_id, sum(stock_levels.on_hand - stock_levels.reserved) as available')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $available[(int) $row->variant_id] = max(0, (int) $row->available);
        }

        return $available;
    }
}
