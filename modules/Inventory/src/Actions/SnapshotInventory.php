<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Inventory\Models\StockLevel;
use Modules\Support\Contracts\Action;

/**
 * Daily stock and valuation snapshot (spec §6.5.6), valued at the weighted-average cost the
 * Catalog holds. Idempotent per date: re-running a day adds nothing.
 */
final class SnapshotInventory implements Action
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly VariantCatalog $catalog,
    ) {}

    public function handle(?Carbon $date = null): int
    {
        $day = ($date ?? now())->toDateString();
        $written = 0;

        StockLevel::query()->orderBy('id')->chunkById(500, function ($levels) use ($day, &$written): void {
            $variants = $this->catalog->findMany($levels->pluck('variant_id')->unique()->values()->all());
            $rows = [];

            foreach ($levels as $level) {
                $cost = $variants[$level->variant_id]->averageCost ?? null;

                $rows[] = [
                    'date' => $day,
                    'variant_id' => $level->variant_id,
                    'location_id' => $level->location_id,
                    'on_hand' => $level->on_hand,
                    'unit_cost_amount' => $cost?->amount,
                    'valuation_amount' => $cost === null ? null : max(0, $level->on_hand) * $cost->amount,
                    'currency' => $cost?->currency->value,
                    'created_at' => now(),
                ];
            }

            $written += $this->db->connection()->table('stock_snapshots_daily')->insertOrIgnore($rows);
        });

        return $written;
    }
}
