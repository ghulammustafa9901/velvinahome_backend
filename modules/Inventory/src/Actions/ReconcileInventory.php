<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Modules\Support\Contracts\Action;

/**
 * Nightly proof that the balance equals the ledger (spec §6.5 rule 4): for every stock row,
 * `on_hand` must equal the sum of its movements. Mismatches are recorded and logged as an
 * alert; they are never "fixed" silently, because a mismatch means something wrote stock
 * outside the Ledger and that must be investigated.
 */
final class ReconcileInventory implements Action
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return int number of mismatched stock rows
     */
    public function handle(): int
    {
        $connection = $this->db->connection();

        $mismatches = $connection->select(
            'SELECT s.variant_id, s.location_id, s.on_hand, coalesce(m.total, 0) AS movements_sum
             FROM stock_levels s
             LEFT JOIN (
                 SELECT variant_id, location_id, sum(quantity) AS total
                 FROM inventory_movements GROUP BY variant_id, location_id
             ) m ON m.variant_id = s.variant_id AND m.location_id = s.location_id
             WHERE s.on_hand <> coalesce(m.total, 0)',
        );

        foreach ($mismatches as $row) {
            $connection->table('inventory_reconciliations')->insert([
                'ran_at' => now(),
                'variant_id' => $row->variant_id,
                'location_id' => $row->location_id,
                'on_hand_recorded' => $row->on_hand,
                'movements_sum' => $row->movements_sum,
                'difference' => $row->on_hand - $row->movements_sum,
            ]);
        }

        if ($mismatches !== []) {
            Log::critical('Inventory reconciliation found mismatches between stock balances and the ledger.', [
                'mismatches' => count($mismatches),
            ]);
        }

        return count($mismatches);
    }
}
