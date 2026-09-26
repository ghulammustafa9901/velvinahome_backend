<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Inventory\Exceptions\InsufficientStock;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Support\Resolver;
use Modules\Support\Contracts\Action;

/**
 * Holds stock for a checkout in progress (spec §6.5 rule 3, ADR-0010).
 *
 * Concurrency-safe by an atomic conditional update rather than read-then-write:
 *
 *   UPDATE stock_levels SET reserved = reserved + :q
 *   WHERE variant_id = :v AND location_id = :l AND on_hand - reserved >= :q
 *
 * PostgreSQL re-evaluates the WHERE clause after acquiring the row lock, so when many buyers
 * race for the last unit exactly one update matches and every other one affects zero rows.
 * Overselling is impossible unless the variant allows backorders.
 *
 * Allocation (spec §6.5): the default location first, then other active locations by
 * priority. One reservation row per call, at the first location that can cover it whole.
 */
final class ReserveStock implements Action
{
    public const DEFAULT_TTL_MINUTES = 30;

    public function __construct(
        private readonly Resolver $resolve,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $variantUlid, int $quantity, string $referenceType, string $referenceUlid, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): StockReservation
    {
        if ($quantity < 1) {
            throw InventoryRuleViolation::nonPositiveQuantity();
        }

        $variant = $this->resolve->variant($variantUlid);

        $locations = Location::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        return $this->db->connection()->transaction(function () use ($variant, $quantity, $referenceType, $referenceUlid, $ttlMinutes, $locations): StockReservation {
            $connection = $this->db->connection();

            foreach ($locations as $index => $location) {
                $connection->statement(
                    'INSERT INTO stock_levels (variant_id, location_id, on_hand, reserved, incoming, updated_at)
                     VALUES (?, ?, 0, 0, 0, now()) ON CONFLICT (variant_id, location_id) DO NOTHING',
                    [$variant->id, $location->id],
                );

                // Backorderable variants reserve at the primary location regardless of stock.
                $backorderHere = $variant->allowBackorder && $index === 0;

                $affected = $connection->update(
                    'UPDATE stock_levels SET reserved = reserved + ?, updated_at = now()
                     WHERE variant_id = ? AND location_id = ? AND (on_hand - reserved >= ? OR ?)',
                    [$quantity, $variant->id, $location->id, $quantity, $backorderHere],
                );

                if ($affected === 1) {
                    return StockReservation::query()->create([
                        'variant_id' => $variant->id,
                        'location_id' => $location->id,
                        'quantity' => $quantity,
                        'reference_type' => $referenceType,
                        'reference_ulid' => $referenceUlid,
                        'status' => 'active',
                        'expires_at' => now()->addMinutes($ttlMinutes),
                    ]);
                }
            }

            $available = (int) StockLevel::query()
                ->where('variant_id', $variant->id)
                ->whereIn('location_id', $locations->pluck('id'))
                ->selectRaw('coalesce(sum(greatest(on_hand - reserved, 0)), 0) as available')
                ->value('available');

            throw InsufficientStock::for($variant->sku, $quantity, $available);
        });
    }
}
